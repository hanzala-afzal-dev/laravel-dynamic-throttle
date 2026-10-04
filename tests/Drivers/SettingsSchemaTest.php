<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\AuditAction;
use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-013 AC-1 on the docker compose MySQL and PostgreSQL: the settings table
| and the audit log. Each test gets a random table prefix, so parallel runs
| never share a table.
*/

it('AC-1: migrates up and down, and keeps every type of a setting and an audit entry', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.settings-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => 'p'.bin2hex(random_bytes(4)).'_',
    ]);
    config()->set('dynamic-throttle.database.connection', "settings-{$driver}");

    RuleSchema::migrate();

    try {
        $created = [
            Schema::connection("settings-{$driver}")->hasTable('dynamic_throttle_settings'),
            Schema::connection("settings-{$driver}")->hasTable('dynamic_throttle_audit_log'),
        ];
        Settings::scale('1.25', until: FakeClock::T0 + 60);
        AuditEntry::create([
            'actor' => 'cli:deploy',
            'action' => AuditAction::SettingsScale,
            'target' => 'setting:scale_factor',
            'before' => null,
            // MySQL's JSON type would put value first, as it sorts keys by length.
            'after' => ['expires_at' => '2027-01-15T08:01:00+00:00', 'value' => '1.25'],
            // Browsers' user agents can be long (the review): SPEC-019 writes them whole.
            'user_agent' => str_repeat('Mozilla/5.0 ', 60),
        ]);
        $setting = Setting::query()->firstOrFail();
        $entry = AuditEntry::query()->firstOrFail();
    } finally {
        RuleSchema::rollback();
    }

    expect($created)->toBe([true, true])
        ->and([Schema::connection("settings-{$driver}")->hasTable('dynamic_throttle_settings'), Schema::connection("settings-{$driver}")->hasTable('dynamic_throttle_audit_log')])
        ->toBe([false, false])
        ->and([$setting->name, $setting->value, $setting->expires_at?->getTimestamp()])->toBe([SettingName::ScaleFactor, '1.25', FakeClock::T0 + 60])
        // Before and after come back exactly as written, keys in order, which MySQL's JSON type would not keep (the review).
        ->and([$entry->getAttribute('action'), $entry->getAttribute('before'), $entry->getAttribute('after')])
        ->toBe([AuditAction::SettingsScale, null, ['expires_at' => '2027-01-15T08:01:00+00:00', 'value' => '1.25']])
        ->and($entry->getAttribute('user_agent'))->toBe(str_repeat('Mozilla/5.0 ', 60));
})->with([
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);
