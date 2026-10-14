<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-017 AC-1 and AC-2: reading rules. The clock stands at 2027-01-15
| 12:00:00 UTC, so every rule is created then.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-1: lists every rule, enabled or not, in evaluation order, with each field', function (): void {
    $b = Throttling::rule(['name' => 'B', 'priority' => 10]);
    $a = Throttling::rule(['name' => 'A', 'priority' => 5, 'enabled' => false, 'description' => 'Off for now.']);
    $c = Throttling::rule(['name' => 'C', 'priority' => 10]);

    $response = DashboardApi::send($this, 'GET', 'rules', user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and(array_column($response->json('data'), 'id'))->toBe([$a->id, $b->id, $c->id])
        ->and($response->json('data.0'))->toBe([
            'id' => $a->id,
            'name' => 'A',
            'description' => 'Off for now.',
            'method' => '*',
            'pattern' => 'api/*',
            'scope' => 'ip',
            'scope_parameter' => null,
            'max_attempts' => 60,
            'window_seconds' => 60,
            'priority' => 5,
            'mode' => 'enforce',
            'on_failure' => 'open',
            'on_missing_identity' => 'ip',
            'response_override' => null,
            'enabled' => false,
            'created_at' => '2027-01-15T12:00:00+00:00',
            'updated_at' => '2027-01-15T12:00:00+00:00',
        ])
        ->and([$response->json('current_page'), $response->json('last_page'), $response->json('per_page'), $response->json('total')])->toBe([1, 1, 100, 3]);
});

it('AC-1: gives 100 rules a page', function (): void {
    // Without the models' events, so 101 rules do not compile 101 manifests.
    Rule::withoutEvents(function (): void {
        foreach (range(1, 101) as $number) {
            Rule::query()->create(RuleSchema::attributes(['name' => "Rule {$number}"]));
        }
    });

    $first = DashboardApi::send($this, 'GET', 'rules', user: DashboardApi::viewer());
    $second = DashboardApi::send($this, 'GET', 'rules?page=2', user: DashboardApi::viewer());

    expect([count($first->json('data')), $first->json('last_page'), $first->json('total')])->toBe([100, 2, 101])
        ->and(array_column($second->json('data'), 'name'))->toBe(['Rule 101']);
});

it('AC-2: shows one rule with the exemptions that name it, not those for every rule', function (): void {
    $rule = Throttling::rule(['name' => 'Login', 'pattern' => 'login', 'response_override' => ['message' => 'Slow down']]);
    $named = Exemption::query()->create(['rule_id' => $rule->id, 'subject' => ExemptionSubject::Ip, 'value' => '10.0.0.0/8', 'reason' => 'Office', 'expires_at' => Carbon::parse('2027-02-01 00:00:00', 'UTC')]);
    Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7']);

    $response = DashboardApi::send($this, 'GET', "rules/{$rule->id}", user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and([$response->json('rule.id'), $response->json('rule.name'), $response->json('rule.response_override')])->toBe([$rule->id, 'Login', ['message' => 'Slow down']])
        ->and($response->json('exemptions'))->toBe([[
            'id' => $named->id,
            'rule_id' => $rule->id,
            'subject' => 'ip',
            'subject_parameter' => null,
            'value' => '10.0.0.0/8',
            'reason' => 'Office',
            'expires_at' => '2027-02-01T00:00:00+00:00',
            'active' => true,
            'created_at' => '2027-01-15T12:00:00+00:00',
            'updated_at' => '2027-01-15T12:00:00+00:00',
        ]]);
});

it('AC-2: answers 404 for an unknown id, and for one that is not a number', function (string $id): void {
    Throttling::rule();

    expect(DashboardApi::send($this, 'GET', "rules/{$id}", user: DashboardApi::viewer())->status())->toBe(404);
})->with(['999', 'abc', '1.5']);
