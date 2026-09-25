<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-009 AC-1 to AC-3, AC-5 and AC-6: the exemptions table and the Exemption
| model, on SQLite. MySQL and PostgreSQL run in tests/Drivers/ExemptionSchemaTest.php.
*/

function useSecondaryExemptionsConnection(): void
{
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'secondary');
}

it('AC-1: creates the exemptions table with its columns, and rolling back drops it', function (): void {
    RuleSchema::migrate();
    $nullable = collect(Schema::getColumns('dynamic_throttle_exemptions'))
        ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['nullable']])
        ->all();
    $foreignKeys = Schema::getForeignKeys('dynamic_throttle_exemptions');

    RuleSchema::rollback();

    expect($nullable)->toBe([
        'id' => false,
        'rule_id' => true,
        'subject' => false,
        'subject_parameter' => true,
        'value' => false,
        'reason' => true,
        'expires_at' => true,
        'created_at' => true,
        'updated_at' => true,
    ])
        ->and($foreignKeys)->toHaveCount(1)
        ->and([$foreignKeys[0]['columns'], $foreignKeys[0]['foreign_table'], $foreignKeys[0]['foreign_columns'], strtolower((string) $foreignKeys[0]['on_delete'])])
        ->toBe([['rule_id'], 'dynamic_throttle_rules', ['id'], 'cascade'])
        ->and(Schema::hasTable('dynamic_throttle_exemptions'))->toBeFalse();
});

it('AC-2: creates the table on the configured connection', function (): void {
    useSecondaryExemptionsConnection();

    RuleSchema::migrate();

    expect(Schema::connection('secondary')->hasTable('dynamic_throttle_exemptions'))->toBeTrue()
        ->and(Schema::hasTable('dynamic_throttle_exemptions'))->toBeFalse();
});

it('AC-2: ships its migration in the folder published with the rules migration', function (): void {
    $migrations = array_map(basename(...), glob(RuleSchema::MIGRATIONS.'/*.php') ?: []);

    expect($migrations)->toBe([
        '2026_09_24_000001_create_dynamic_throttle_rules_table.php',
        '2026_09_25_000001_create_dynamic_throttle_exemptions_table.php',
    ]);
});

it('AC-3: deletes a rule\'s exemptions with the rule, and no other rule\'s or global ones', function (): void {
    // Laravel's own SQLite config enforces foreign keys (DB_FOREIGN_KEYS); Testbench's testing connection does not.
    config()->set('database.connections.testing.foreign_key_constraints', true);
    DB::purge('testing');
    RuleSchema::migrate();
    $deleted = Exemptions::rule();
    $kept = Exemptions::rule();
    Exemption::forIp('10.0.0.0/8')->forRule($deleted)->save();
    Exemption::forIp('10.0.0.1')->forRule($deleted)->save();
    $other = Exemption::forIp('10.0.0.2')->forRule($kept);
    $other->save();
    $global = Exemption::forIp('10.0.0.3');
    $global->save();

    $deleted->delete();

    expect(Exemption::query()->orderBy('id')->pluck('id')->all())->toBe([$other->id, $global->id]);
});

it('AC-3: stores exemptions on the configured connection', function (): void {
    useSecondaryExemptionsConnection();
    RuleSchema::migrate();

    Exemption::forIp('10.0.0.0/8')->save();

    expect(DB::connection('secondary')->table('dynamic_throttle_exemptions')->count())->toBe(1);
});

it('AC-5: casts the subject to ExemptionSubject and the expiry to a date, as saved and when read back', function (): void {
    RuleSchema::migrate();
    $saved = Exemption::forCustom('tenant', 'acme')->until(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $saved->save();

    foreach (['saved' => $saved, 'read back' => Exemption::query()->findOrFail($saved->id)] as $exemption) {
        expect($exemption->subject)->toBe(ExemptionSubject::Custom)
            ->and($exemption->expires_at)->toBeInstanceOf(Carbon::class)
            ->and($exemption->expires_at?->getTimestamp())->toBe(FakeClock::T0 + 60);
    }
});

it('AC-5: relates an exemption to its rule, and a rule to its exemptions', function (): void {
    RuleSchema::migrate();
    $rule = Exemptions::rule();
    $exemption = Exemption::forIp('10.0.0.0/8')->forRule($rule);
    $exemption->save();
    $global = Exemption::forIp('10.0.0.1');
    $global->save();

    expect($exemption->fresh()?->rule?->is($rule))->toBeTrue()
        ->and($global->fresh()?->rule)->toBeNull()
        ->and($rule->exemptions()->pluck('id')->all())->toBe([$exemption->id]);
});

it('AC-6: builds an unsaved ip exemption', function (): void {
    $exemption = Exemption::forIp('10.0.0.0/8');

    expect($exemption->exists)->toBeFalse()
        ->and([$exemption->subject, $exemption->subject_parameter, $exemption->value])->toBe([ExemptionSubject::Ip, null, '10.0.0.0/8'])
        ->and([$exemption->rule_id, $exemption->expires_at, $exemption->reason])->toBe([null, null, null]);
});

it('AC-6: builds an unsaved user exemption from the user\'s class and id', function (): void {
    $exemption = Exemption::forUser(new TestUser(['id' => 42]));

    expect($exemption->exists)->toBeFalse()
        ->and([$exemption->subject, $exemption->subject_parameter, $exemption->value])->toBe([ExemptionSubject::User, null, TestUser::class.':42']);
});

it('AC-6: builds an unsaved custom exemption from the scope\'s name and a value', function (): void {
    $exemption = Exemption::forCustom('tenant', 'acme');

    expect($exemption->exists)->toBeFalse()
        ->and([$exemption->subject, $exemption->subject_parameter, $exemption->value])->toBe([ExemptionSubject::Custom, 'tenant', 'acme']);
});

it('AC-6: sets the rule, the expiry and the reason', function (): void {
    RuleSchema::migrate();
    $rule = Exemptions::rule();

    $exemption = Exemption::forIp('10.0.0.0/8')
        ->forRule($rule)
        ->until(Carbon::createFromTimestamp(FakeClock::T0 + 60))
        ->because('The office network');

    expect($exemption->exists)->toBeFalse()
        ->and([$exemption->rule_id, $exemption->expires_at?->getTimestamp(), $exemption->reason])->toBe([$rule->id, FakeClock::T0 + 60, 'The office network']);
});

it('AC-6: saves what the helpers build', function (): void {
    RuleSchema::migrate();
    $rule = Exemptions::rule();

    Exemption::forUser(new TestUser(['id' => 42]))->forRule($rule)->because('A support import')->save();
    $read = Exemption::query()->sole();

    expect([$read->subject, $read->value, $read->rule_id, $read->reason])->toBe([ExemptionSubject::User, TestUser::class.':42', $rule->id, 'A support import'])
        ->and(Rule::query()->count())->toBe(1);
});
