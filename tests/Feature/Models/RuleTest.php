<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-005: the rules table and the Rule model, on SQLite. MySQL and
| PostgreSQL run in tests/Drivers/RuleSchemaTest.php.
*/

function useSecondaryConnection(): void
{
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'secondary');
}

it('AC-9: creates the rules table with its columns, and rolling back drops it', function (): void {
    RuleSchema::migrate();
    $nullable = collect(Schema::getColumns('dynamic_throttle_rules'))
        ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['nullable']])
        ->all();

    RuleSchema::rollback();

    expect($nullable)->toBe([
        'id' => false,
        'name' => false,
        'description' => true,
        'method' => false,
        'pattern' => false,
        'scope' => false,
        'scope_parameter' => true,
        'max_attempts' => false,
        'window_seconds' => false,
        'priority' => false,
        'mode' => false,
        'on_failure' => false,
        'on_missing_identity' => false,
        'response_override' => true,
        'enabled' => false,
        'created_at' => true,
        'updated_at' => true,
    ])->and(Schema::hasTable('dynamic_throttle_rules'))->toBeFalse();
});

it('AC-10: creates the table on the configured connection', function (): void {
    useSecondaryConnection();

    RuleSchema::migrate();

    expect(Schema::connection('secondary')->hasTable('dynamic_throttle_rules'))->toBeTrue()
        ->and(Schema::hasTable('dynamic_throttle_rules'))->toBeFalse();
});

it('AC-13: gives a new rule every default, on the returned model and when read back', function (): void {
    RuleSchema::migrate();
    $created = Rule::create(RuleSchema::attributes());

    foreach (['returned' => $created, 'read back' => Rule::query()->findOrFail($created->getKey())] as $rule) {
        expect($rule->method)->toBe(RequestMethod::Any)
            ->and($rule->priority)->toBe(100)
            ->and($rule->mode)->toBe(RuleMode::Enforce)
            ->and($rule->on_failure)->toBe(FailurePolicy::Open)
            ->and($rule->on_missing_identity)->toBe(MissingIdentity::FallBackToIp)
            ->and($rule->enabled)->toBeTrue()
            ->and($rule->scope_parameter)->toBeNull()
            ->and($rule->response_override)->toBeNull()
            ->and($rule->description)->toBeNull();
    }
});

it('AC-14: keeps every type through a round trip on SQLite', function (): void {
    RuleSchema::migrate();

    [$rule, $override] = RuleSchema::roundTrip();

    expect([$rule->method, $rule->scope, $rule->mode, $rule->on_failure, $rule->on_missing_identity])
        ->toBe([RequestMethod::Post, Scope::Header, RuleMode::DryRun, FailurePolicy::Closed, MissingIdentity::Skip])
        ->and([$rule->max_attempts, $rule->window_seconds, $rule->priority])->toBe([60, 60, 5])
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->response_override)->toBe($override)
        ->and($rule->scope_parameter)->toBe('X-Api-Key');
});

it('AC-15: stores rules on the configured connection', function (): void {
    useSecondaryConnection();
    RuleSchema::migrate();

    Rule::create(RuleSchema::attributes());

    expect(DB::connection('secondary')->table('dynamic_throttle_rules')->count())->toBe(1);
});
