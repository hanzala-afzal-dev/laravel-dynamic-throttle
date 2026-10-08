<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RuleChange;
use Afzal\DynamicThrottle\Events\RuleChanged;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-015 AC-21: RuleChanged, dispatched after a rule's change commits, with
| the changed attributes before and after, as the audit log keeps them: the
| columns in table order, without the timestamps, with enums as their values.
*/

beforeEach(function (): void {
    Throttling::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return list<RuleChanged>
 */
function ruleChanges(): array
{
    return Event::dispatched(RuleChanged::class)->map(fn (array $arguments): RuleChanged => $arguments[0])->values()->all();
}

/**
 * Rule 1 as RuleSchema creates it, as RuleChanged carries it.
 *
 * @return array<string, mixed>
 */
function ruleOneAttributes(): array
{
    return [
        'id' => 1,
        'name' => 'API',
        'description' => null,
        'method' => '*',
        'pattern' => 'api/*',
        'scope' => 'ip',
        'scope_parameter' => null,
        'max_attempts' => 60,
        'window_seconds' => 60,
        'priority' => 100,
        'mode' => 'enforce',
        'on_failure' => 'open',
        'on_missing_identity' => 'ip',
        'response_override' => null,
        'enabled' => true,
    ];
}

it('AC-21: dispatches RuleChanged for a new rule, with its attributes after, and survives serialize()', function (): void {
    Event::fake([RuleChanged::class]);

    Rule::create(RuleSchema::attributes());
    $changes = ruleChanges();

    expect($changes)->toHaveCount(1)
        ->and(get_object_vars($changes[0]))->toBe([
            'ruleId' => '1',
            'ruleName' => 'API',
            'change' => RuleChange::Created,
            'before' => null,
            'after' => ruleOneAttributes(),
        ])
        ->and(unserialize(serialize($changes[0])))->toEqual($changes[0]);
});

it('AC-21: dispatches RuleChanged for an update, with only the changed attributes before and after, in column order', function (): void {
    $rule = Rule::create(RuleSchema::attributes());
    Event::fake([RuleChanged::class]);

    $rule->update(['max_attempts' => 120, 'name' => 'API v2']);

    expect(array_map(fn (RuleChanged $change): array => get_object_vars($change), ruleChanges()))->toBe([[
        'ruleId' => '1',
        'ruleName' => 'API v2',
        'change' => RuleChange::Updated,
        'before' => ['name' => 'API', 'max_attempts' => 60],
        'after' => ['name' => 'API v2', 'max_attempts' => 120],
    ]]);
});

it('AC-21: dispatches disabling and enabling a rule as updates of enabled', function (): void {
    $rule = Rule::create(RuleSchema::attributes());
    Event::fake([RuleChanged::class]);

    $rule->update(['enabled' => false]);
    $rule->update(['enabled' => true]);

    expect(array_map(fn (RuleChanged $change): array => [$change->change, $change->before, $change->after], ruleChanges()))->toBe([
        [RuleChange::Updated, ['enabled' => true], ['enabled' => false]],
        [RuleChange::Updated, ['enabled' => false], ['enabled' => true]],
    ]);
});

it('AC-21: dispatches nothing for a save that changes nothing, or only the timestamps', function (): void {
    $rule = Rule::create(RuleSchema::attributes());
    Event::fake([RuleChanged::class]);

    $rule->save();
    $this->travel(5)->minutes();
    $rule->touch();

    expect(ruleChanges())->toBe([]);
});

it('AC-21: dispatches RuleChanged for a deleted rule, with its attributes before', function (): void {
    $rule = Rule::create(RuleSchema::attributes());
    Event::fake([RuleChanged::class]);

    $rule->delete();

    expect(array_map(fn (RuleChanged $change): array => get_object_vars($change), ruleChanges()))->toBe([[
        'ruleId' => '1',
        'ruleName' => 'API',
        'change' => RuleChange::Deleted,
        'before' => ruleOneAttributes(),
        'after' => null,
    ]]);
});

it('AC-21: dispatches after the commit, and nothing for a change that rolls back', function (): void {
    Event::fake([RuleChanged::class]);
    $inside = null;

    DB::transaction(function () use (&$inside): void {
        Rule::create(RuleSchema::attributes());
        $inside = count(ruleChanges());
    });

    try {
        DB::transaction(function (): void {
            Rule::create(RuleSchema::attributes(['name' => 'Rolled back']));

            throw new RuntimeException('Roll back.');
        });
    } catch (RuntimeException) {
        // The second rule was never saved.
    }

    expect([$inside, array_map(fn (RuleChanged $change): string => $change->ruleName, ruleChanges())])->toBe([0, ['API']]);
});

it('AC-21: reports a listener that throws, and the change stands', function (): void {
    Exceptions::fake();
    Event::listen(RuleChanged::class, fn (): never => throw new RuntimeException('The Slack hook failed.'));

    Rule::create(RuleSchema::attributes());

    expect(Rule::query()->pluck('name')->all())->toBe(['API'])
        ->and(array_map(fn (Throwable $exception): string => $exception->getMessage(), Exceptions::reported()))->toBe(['The Slack hook failed.']);
});

it('AC-21: keeps and compiles a change made on a rule loaded with some of its columns, and dispatches what it knows', function (): void {
    Exceptions::fake();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    Event::fake([RuleChanged::class]);

    Rule::query()->select(['id', 'enabled'])->firstOrFail()->update(['enabled' => false]);

    // The manifest was compiled without the rule, so it limits nothing.
    $this->get('api/x')->assertOk();

    expect(array_map(fn (RuleChanged $change): array => get_object_vars($change), ruleChanges()))->toBe([[
        'ruleId' => '1',
        'ruleName' => '',
        'change' => RuleChange::Updated,
        'before' => ['enabled' => true],
        'after' => ['enabled' => false],
    ]])
        ->and(Exceptions::reported())->toBe([]);
});

it('AC-21: deletes and compiles a rule loaded with its id only, and dispatches what it knows', function (): void {
    Exceptions::fake();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    Event::fake([RuleChanged::class]);

    Rule::query()->select(['id'])->firstOrFail()->delete();

    $this->get('api/x')->assertOk();

    expect(array_map(fn (RuleChanged $change): array => get_object_vars($change), ruleChanges()))->toBe([[
        'ruleId' => '1',
        'ruleName' => '',
        'change' => RuleChange::Deleted,
        'before' => ['id' => 1],
        'after' => null,
    ]])
        ->and([Rule::query()->count(), Exceptions::reported()])->toBe([0, []]);
});

it('AC-21: dispatches a change made on a partly loaded rule when the app forbids reading missing attributes', function (): void {
    Rule::create(RuleSchema::attributes());
    Event::fake([RuleChanged::class]);
    Model::preventAccessingMissingAttributes();

    try {
        Rule::query()->select(['id', 'max_attempts'])->firstOrFail()->update(['max_attempts' => 5]);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }

    expect(array_map(fn (RuleChanged $change): array => [$change->ruleName, $change->before, $change->after], ruleChanges()))
        ->toBe([['', ['max_attempts' => 60], ['max_attempts' => 5]]]);
});
