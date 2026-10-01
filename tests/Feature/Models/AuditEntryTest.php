<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-013 AC-1 and AC-23: the audit log (ADR-0015 point 3, decision 1), on
| SQLite. It is append-only: the model refuses to change or delete an entry.
| MySQL and PostgreSQL run in tests/Drivers/SettingsSchemaTest.php.
*/

function auditEntry(): AuditEntry
{
    return AuditEntry::create([
        'actor' => 'cli:deploy',
        'action' => AuditAction::SettingsBypass,
        'target' => 'setting:bypass_all',
        'before' => null,
        'after' => ['value' => '1', 'expires_at' => '2027-01-01T01:00:00+00:00'],
    ]);
}

it('AC-1: creates the audit log table with its columns, and rolling back drops it', function (): void {
    RuleSchema::migrate();
    $nullable = collect(Schema::getColumns('dynamic_throttle_audit_log'))
        ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['nullable']])
        ->all();

    RuleSchema::rollback();

    expect($nullable)->toBe([
        'id' => false,
        'actor' => false,
        'action' => false,
        'target' => false,
        'before' => true,
        'after' => true,
        'ip' => true,
        'user_agent' => true,
        'created_at' => false,
    ])
        ->and(Schema::hasTable('dynamic_throttle_audit_log'))->toBeFalse();
});

it('AC-1: creates the table on the configured connection, and writes entries there', function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'secondary');
    RuleSchema::migrate();

    auditEntry();

    expect(Schema::connection('secondary')->hasTable('dynamic_throttle_audit_log'))->toBeTrue()
        ->and(DB::connection('secondary')->table('dynamic_throttle_audit_log')->count())->toBe(1);
});

it('AC-23: keeps the action as AuditAction, before and after as arrays, and the time it was written', function (): void {
    RuleSchema::migrate();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    $written = auditEntry();

    $entry = AuditEntry::query()->findOrFail($written->getKey());

    expect($entry->getAttribute('action'))->toBe(AuditAction::SettingsBypass)
        ->and([$entry->getAttribute('before'), $entry->getAttribute('after')])
        ->toBe([null, ['value' => '1', 'expires_at' => '2027-01-01T01:00:00+00:00']])
        ->and($entry->getAttribute('created_at')?->getTimestamp())->toBe(FakeClock::T0);
});

it('AC-23: refuses to change an entry', function (): void {
    RuleSchema::migrate();
    $entry = auditEntry();

    expect(fn (): bool => $entry->update(['actor' => 'cli:someone-else']))->toThrow(LogicException::class, 'append-only')
        ->and(AuditEntry::query()->value('actor'))->toBe('cli:deploy');
});

it('AC-23: refuses to delete an entry', function (): void {
    RuleSchema::migrate();
    $entry = auditEntry();

    expect(fn (): ?bool => $entry->delete())->toThrow(LogicException::class, 'append-only')
        ->and(AuditEntry::query()->count())->toBe(1);
});
