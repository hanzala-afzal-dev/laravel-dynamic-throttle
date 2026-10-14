<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;

/*
| SPEC-017 AC-12 to AC-14 and AC-18: creating, changing and deleting rules,
| each with one audit entry written in the same transaction. The clock stands
| at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const LOGIN_RULE = ['name' => 'Login', 'method' => 'POST', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60];

/**
 * The ids of the rules the stored manifest holds.
 *
 * @return list<string>
 */
function manifestRuleIds(): array
{
    return array_map(strval(...), array_column(Exemptions::stored()['rules'], 'id'));
}

/*
| AC-12: creating.
*/

it('AC-12: creates a rule, answers 201 with it, compiles it, and records rule.create', function (): void {
    $response = DashboardApi::send($this, 'POST', 'rules', LOGIN_RULE, DashboardApi::manager());

    $rule = Rule::query()->sole();
    $stored = [
        'name' => 'Login',
        'description' => null,
        'method' => 'POST',
        'pattern' => 'login',
        'scope' => 'ip',
        'scope_parameter' => null,
        'max_attempts' => 5,
        'window_seconds' => 60,
        'priority' => 100,
        'mode' => 'enforce',
        'on_failure' => 'open',
        'on_missing_identity' => 'ip',
        'response_override' => null,
        'enabled' => true,
    ];

    expect($response->status())->toBe(201)
        ->and($response->json('rule'))->toBe(['id' => $rule->id, ...$stored, 'created_at' => '2027-01-15T12:00:00+00:00', 'updated_at' => '2027-01-15T12:00:00+00:00'])
        ->and([$response->json('changed'), $response->json('manifest')])->toBe([true, ['compiled' => true, 'version' => Exemptions::stored()['version']]])
        ->and(manifestRuleIds())->toBe([(string) $rule->id])
        ->and(Settings::audit())->toBe([[
            'actor' => DashboardApi::managerActor(),
            'action' => 'rule.create',
            'target' => "rule:{$rule->id}",
            'before' => null,
            'after' => $stored,
            'ip' => '127.0.0.1',
            'user_agent' => DashboardApi::USER_AGENT,
        ]]);
});

it('AC-12: refuses an invalid rule with a 422, and saves and records nothing', function (): void {
    $response = DashboardApi::send($this, 'POST', 'rules', [...LOGIN_RULE, 'max_attempts' => 0], DashboardApi::manager());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, ['max_attempts']])
        ->and([Rule::query()->count(), Settings::audit()])->toBe([0, []]);
});

/*
| AC-13: changing.
*/

it('AC-13: changes only the fields it is given, and records them before and after', function (): void {
    $rule = Throttling::rule();
    $this->travelTo(Carbon::parse('2027-01-15 12:05:00', 'UTC'));

    $response = DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", ['max_attempts' => 120, 'description' => 'Raised for the launch.'], DashboardApi::manager());

    $entry = Settings::audit()[0] ?? null;

    expect($response->status())->toBe(200)
        ->and([$response->json('rule.max_attempts'), $response->json('rule.description'), $response->json('rule.pattern'), $response->json('rule.updated_at')])
        ->toBe([120, 'Raised for the launch.', 'api/*', '2027-01-15T12:05:00+00:00'])
        ->and([$response->json('changed'), $response->json('manifest.compiled')])->toBe([true, true])
        ->and(Settings::audit())->toHaveCount(1)
        ->and([$entry['action'] ?? null, $entry['target'] ?? null])->toBe(['rule.update', "rule:{$rule->id}"])
        ->and($entry['before'] ?? null)->toEqual(['description' => null, 'max_attempts' => 60])
        ->and($entry['after'] ?? null)->toEqual(['description' => 'Raised for the launch.', 'max_attempts' => 120]);
});

it('AC-13: records a patch of enabled alone as rule.disable and rule.enable, as the CLI does', function (): void {
    $rule = Throttling::rule();

    DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", ['enabled' => false, 'confirm' => true], DashboardApi::manager())->assertOk();
    DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", ['enabled' => true], DashboardApi::manager())->assertOk();

    expect(array_map(fn (array $entry): array => [$entry['action'], $entry['before'], $entry['after']], Settings::audit()))->toBe([
        ['rule.disable', ['enabled' => true], ['enabled' => false]],
        ['rule.enable', ['enabled' => false], ['enabled' => true]],
    ]);
});

it('AC-13: answers changed false, with no manifest, and records nothing, when the patch changes nothing', function (): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", ['max_attempts' => 60, 'pattern' => 'api/*'], DashboardApi::manager());

    expect([$response->status(), $response->json('changed'), $response->json('manifest')])->toBe([200, false, null])
        ->and(Settings::audit())->toBe([]);
});

it('AC-13: needs confirm to disable or dry-run an enforcing rule, and changes nothing without it', function (array $patch, string $message): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", $patch, DashboardApi::manager());

    expect([$response->status(), $response->json('message')])->toBe([409, str_replace('{id}', (string) $rule->id, $message)])
        ->and([$rule->fresh()?->enabled, $rule->fresh()?->mode])->toBe([true, RuleMode::Enforce])
        ->and(Settings::audit())->toBe([]);
})->with([
    'disable' => [['enabled' => false], 'Rule {id} (API) stops limiting requests until it is enabled again.'],
    'dry-run' => [['mode' => 'dry_run'], 'Rule {id} (API) stops limiting requests while it runs in dry-run.'],
]);

it('AC-13: checks the patch against the whole rule', function (): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'PATCH', "rules/{$rule->id}", ['scope' => 'header'], DashboardApi::manager());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, ['scope_parameter']])
        ->and($rule->fresh()?->scope->value)->toBe('ip');
});

/*
| AC-14: deleting.
*/

it('AC-14: needs confirm to delete an enforcing rule', function (): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'DELETE', "rules/{$rule->id}", user: DashboardApi::manager());

    expect([$response->status(), $response->json('message')])->toBe([409, "Rule {$rule->id} (API) is deleted, and stops limiting requests."])
        ->and(Rule::query()->count())->toBe(1);
});

it('AC-14: deletes a rule and the exemptions that name it, and records rule.delete once, counting them', function (): void {
    $rule = Throttling::rule(['name' => 'Login', 'pattern' => 'login']);
    Exemption::query()->create(['rule_id' => $rule->id, 'subject' => ExemptionSubject::Ip, 'value' => '10.0.0.1']);
    Exemption::query()->create(['rule_id' => $rule->id, 'subject' => ExemptionSubject::Ip, 'value' => '10.0.0.2']);
    $everyRule = Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7']);

    $response = DashboardApi::send($this, 'DELETE', "rules/{$rule->id}", ['confirm' => true], DashboardApi::manager());

    $entry = Settings::audit()[0] ?? null;

    expect([$response->status(), $response->json('rule.id'), $response->json('changed'), $response->json('manifest.compiled')])->toBe([200, $rule->id, true, true])
        ->and([Rule::query()->count(), Exemption::query()->pluck('id')->all()])->toBe([0, [$everyRule->id]])
        ->and(manifestRuleIds())->toBe([])
        ->and(Settings::audit())->toHaveCount(1)
        ->and([$entry['action'] ?? null, $entry['target'] ?? null, $entry['after'] ?? 'missing'])->toBe(['rule.delete', "rule:{$rule->id}", null])
        ->and([$entry['before']['name'] ?? null, $entry['before']['pattern'] ?? null, $entry['before']['exemptions_deleted'] ?? null])->toBe(['Login', 'login', 2]);
});

it('AC-14: deletes a dry-run rule without confirm', function (): void {
    $rule = Throttling::rule(['mode' => RuleMode::DryRun]);

    $response = DashboardApi::send($this, 'DELETE', "rules/{$rule->id}", user: DashboardApi::manager());

    expect([$response->status(), Rule::query()->count()])->toBe([200, 0]);
});

it('AC-12 to AC-14: answers 404 for a rule that does not exist, and changes nothing', function (string $method): void {
    $response = DashboardApi::send($this, $method, 'rules/999', ['max_attempts' => 5, 'confirm' => true], DashboardApi::manager());

    expect([$response->status(), Settings::audit()])->toBe([404, []]);
})->with(['PATCH', 'DELETE']);

/*
| AC-18: a change that is saved, but whose compile fails.
*/

it('AC-18: keeps a rule whose compile fails, and says so in manifest', function (): void {
    $store = new class extends ArrayStore
    {
        public function forever($key, $value): bool
        {
            throw new RuntimeException('The store is down.');
        }
    };
    app()->instance(ManifestCompiler::class, new ManifestCompiler(new Repository($store), new KeyBuilder('dt:')));

    $response = DashboardApi::send($this, 'POST', 'rules', LOGIN_RULE, DashboardApi::manager());

    expect([$response->status(), $response->json('changed'), $response->json('manifest')])
        ->toBe([201, true, ['compiled' => false, 'error' => 'The manifest could not be compiled: The store is down.']])
        ->and([Rule::query()->count(), count(Settings::audit())])->toBe([1, 1]);
});
