<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-017 AC-3, AC-15 and AC-16: listing, creating and deleting exemptions,
| each change with one audit entry. The clock stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * An exemption for an address, saved through the model, which compiles it.
 *
 * @param  array<string, mixed>  $attributes
 */
function apiExemption(array $attributes = []): Exemption
{
    return Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7', ...$attributes]);
}

/**
 * The ids of the exemptions the stored manifest holds.
 *
 * @return list<string>
 */
function manifestExemptionIds(): array
{
    return array_map(strval(...), array_column(Exemptions::stored()['exemptions'], 'id'));
}

/*
| AC-3: listing.
*/

it('AC-3: lists every exemption, newest first, with active false once it has expired', function (): void {
    $api = Throttling::rule(['name' => 'API']);
    $login = Throttling::rule(['name' => 'Login', 'pattern' => 'login']);
    $everyRule = apiExemption();
    $expired = apiExemption(['rule_id' => $api->id, 'value' => '10.0.0.1', 'expires_at' => Carbon::parse('2027-01-15 13:00:00', 'UTC')]);
    $forLogin = apiExemption(['rule_id' => $login->id, 'value' => '10.0.0.2']);
    $this->travelTo(Carbon::parse('2027-01-15 14:00:00', 'UTC'));

    $response = DashboardApi::send($this, 'GET', 'exemptions', user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and(array_column($response->json('data'), 'id'))->toBe([$forLogin->id, $expired->id, $everyRule->id])
        ->and(array_column($response->json('data'), 'active'))->toBe([true, false, true])
        ->and($response->json('data.1'))->toBe([
            'id' => $expired->id,
            'rule_id' => $api->id,
            'subject' => 'ip',
            'subject_parameter' => null,
            'value' => '10.0.0.1',
            'reason' => null,
            'expires_at' => '2027-01-15T13:00:00+00:00',
            'active' => false,
            'created_at' => '2027-01-15T12:00:00+00:00',
            'updated_at' => '2027-01-15T12:00:00+00:00',
        ])
        ->and([$response->json('current_page'), $response->json('last_page'), $response->json('per_page'), $response->json('total')])->toBe([1, 1, 100, 3]);
});

it('AC-3: lists one rule\'s exemptions with ?rule, and an empty page for a rule that does not exist', function (): void {
    $login = Throttling::rule(['name' => 'Login', 'pattern' => 'login']);
    apiExemption();
    $forLogin = apiExemption(['rule_id' => $login->id, 'value' => '10.0.0.2']);

    $one = DashboardApi::send($this, 'GET', "exemptions?rule={$login->id}", user: DashboardApi::viewer());
    $none = DashboardApi::send($this, 'GET', 'exemptions?rule=999', user: DashboardApi::viewer());
    $invalid = DashboardApi::send($this, 'GET', 'exemptions?rule=abc', user: DashboardApi::viewer());

    expect(array_column($one->json('data'), 'id'))->toBe([$forLogin->id])
        ->and([$none->status(), $none->json('data')])->toBe([200, []])
        ->and([$invalid->status(), array_keys($invalid->json('errors'))])->toBe([422, ['rule']]);
});

it('AC-3: gives 100 exemptions a page', function (): void {
    // Without the models' events, so 101 exemptions do not compile 101 manifests.
    Exemption::withoutEvents(function (): void {
        foreach (range(1, 101) as $number) {
            apiExemption(['value' => "10.0.0.{$number}"]);
        }
    });

    $second = DashboardApi::send($this, 'GET', 'exemptions?page=2', user: DashboardApi::viewer());

    expect([$second->json('last_page'), $second->json('total'), array_column($second->json('data'), 'value')])->toBe([2, 101, ['10.0.0.1']]);
});

/*
| AC-15: creating.
*/

it('AC-15: creates an exemption, answers 201 with it, compiles it, and records exemption.create', function (): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'POST', 'exemptions', [
        'subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => $rule->id, 'reason' => 'The office', 'expires_at' => '2027-02-01T00:00:00+00:00',
    ], DashboardApi::manager());

    $exemption = Exemption::query()->sole();
    $stored = ['rule_id' => $rule->id, 'subject' => 'ip', 'subject_parameter' => null, 'value' => '10.0.0.0/8', 'reason' => 'The office', 'expires_at' => '2027-02-01T00:00:00+00:00'];

    expect($response->status())->toBe(201)
        ->and($response->json('exemption'))->toBe(['id' => $exemption->id, ...$stored, 'active' => true, 'created_at' => '2027-01-15T12:00:00+00:00', 'updated_at' => '2027-01-15T12:00:00+00:00'])
        ->and([$response->json('changed'), $response->json('manifest')])->toBe([true, ['compiled' => true, 'version' => Exemptions::stored()['version']]])
        ->and(manifestExemptionIds())->toBe([(string) $exemption->id])
        ->and(Settings::audit())->toBe([[
            'actor' => DashboardApi::managerActor(),
            'action' => 'exemption.create',
            'target' => "exemption:{$exemption->id}",
            'before' => null,
            'after' => $stored,
            'ip' => '127.0.0.1',
            'user_agent' => DashboardApi::USER_AGENT,
        ]]);
});

it('AC-15: needs confirm for a range wider than a /8, and saves nothing without it', function (): void {
    $refused = DashboardApi::send($this, 'POST', 'exemptions', ['subject' => 'ip', 'value' => '0.0.0.0/0'], DashboardApi::manager());

    expect([$refused->status(), $refused->json('message')])->toBe([409, 'The exemption frees every client in 0.0.0.0/0 from every rule.'])
        ->and([Exemption::query()->count(), Settings::audit()])->toBe([0, []]);

    $confirmed = DashboardApi::send($this, 'POST', 'exemptions', ['subject' => 'ip', 'value' => '0.0.0.0/0', 'confirm' => true], DashboardApi::manager());

    expect([$confirmed->status(), Exemption::query()->count(), count(Settings::audit())])->toBe([201, 1, 1]);
});

it('AC-15: refuses an invalid exemption with a 422, and saves and records nothing', function (array $input, string $field): void {
    $response = DashboardApi::send($this, 'POST', 'exemptions', $input, DashboardApi::manager());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, [$field]])
        ->and([Exemption::query()->count(), Settings::audit()])->toBe([0, []]);
})->with([
    'not an address' => [['subject' => 'ip', 'value' => 'nope'], 'value'],
    'a rule that does not exist' => [['subject' => 'ip', 'value' => '10.0.0.1', 'rule_id' => 999], 'rule_id'],
    'an expiry in the past' => [['subject' => 'ip', 'value' => '10.0.0.1', 'expires_at' => '2027-01-15T11:00:00+00:00'], 'expires_at'],
]);

/*
| AC-16: deleting.
*/

it('AC-16: deletes an exemption without confirm, and records exemption.delete with it as before', function (): void {
    $exemption = apiExemption(['value' => '0.0.0.0/0', 'reason' => 'Load test']);
    $kept = apiExemption();

    $response = DashboardApi::send($this, 'DELETE', "exemptions/{$exemption->id}", user: DashboardApi::manager());

    expect([$response->status(), $response->json('exemption.id'), $response->json('changed'), $response->json('manifest.compiled')])->toBe([200, $exemption->id, true, true])
        ->and(Exemption::query()->pluck('id')->all())->toBe([$kept->id])
        ->and(manifestExemptionIds())->toBe([(string) $kept->id])
        ->and(Settings::audit())->toBe([[
            'actor' => DashboardApi::managerActor(),
            'action' => 'exemption.delete',
            'target' => "exemption:{$exemption->id}",
            'before' => ['rule_id' => null, 'subject' => 'ip', 'subject_parameter' => null, 'value' => '0.0.0.0/0', 'reason' => 'Load test', 'expires_at' => null],
            'after' => null,
            'ip' => '127.0.0.1',
            'user_agent' => DashboardApi::USER_AGENT,
        ]]);
});

it('AC-16: answers 404 for an exemption that does not exist, and records nothing', function (string $id): void {
    $response = DashboardApi::send($this, 'DELETE', "exemptions/{$id}", user: DashboardApi::manager());

    expect([$response->status(), Settings::audit()])->toBe([404, []]);
})->with(['999', 'abc']);
