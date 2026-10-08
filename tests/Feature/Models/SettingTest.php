<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\SettingName;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-013 AC-1: the settings table and the Setting model, on SQLite. MySQL and
| PostgreSQL run in tests/Drivers/SettingsSchemaTest.php. One row per setting
| that is on (decision 3).
*/

it('AC-1: creates the settings table with its columns, and rolling back drops it', function (): void {
    RuleSchema::migrate();
    $nullable = collect(Schema::getColumns('dynamic_throttle_settings'))
        ->mapWithKeys(fn (array $column): array => [$column['name'] => $column['nullable']])
        ->all();
    $unique = collect(Schema::getIndexes('dynamic_throttle_settings'))
        ->filter(fn (array $index): bool => $index['unique'] && ! $index['primary'])
        ->map(fn (array $index): array => $index['columns'])
        ->values()
        ->all();

    RuleSchema::rollback();

    expect($nullable)->toBe([
        'id' => false,
        'name' => false,
        'value' => false,
        'expires_at' => true,
        'created_at' => true,
        'updated_at' => true,
    ])
        ->and($unique)->toBe([['name']])
        ->and(Schema::hasTable('dynamic_throttle_settings'))->toBeFalse();
});

it('AC-1: creates the table on the configured connection, and stores settings there', function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'secondary');
    RuleSchema::migrate();

    Settings::bypass();

    expect(Schema::connection('secondary')->hasTable('dynamic_throttle_settings'))->toBeTrue()
        ->and(Schema::hasTable('dynamic_throttle_settings'))->toBeFalse()
        ->and(DB::connection('secondary')->table('dynamic_throttle_settings')->count())->toBe(1);
});

it('AC-1: ships its migrations in the folder published with the rules migration', function (): void {
    $migrations = array_map(basename(...), glob(RuleSchema::MIGRATIONS.'/*.php') ?: []);

    expect($migrations)->toBe([
        '2026_09_24_000001_create_dynamic_throttle_rules_table.php',
        '2026_09_25_000001_create_dynamic_throttle_exemptions_table.php',
        '2026_10_01_000001_create_dynamic_throttle_settings_table.php',
        '2026_10_01_000002_create_dynamic_throttle_audit_log_table.php',
        // SPEC-015's hourly metrics.
        '2026_10_04_000001_create_dynamic_throttle_metrics_hourly_table.php',
    ]);
});

it('AC-1: holds one row per setting', function (): void {
    RuleSchema::migrate();
    Settings::bypass();

    expect(fn (): Setting => Settings::bypass())->toThrow(UniqueConstraintViolationException::class);
});

it('AC-1: casts the name to SettingName and the end time to a date, as saved and when read back', function (): void {
    RuleSchema::migrate();
    $saved = Settings::scale('1.5', until: FakeClock::T0 + 60);

    foreach (['saved' => $saved, 'read back' => Setting::query()->findOrFail($saved->id)] as $setting) {
        expect($setting->name)->toBe(SettingName::ScaleFactor)
            ->and($setting->value)->toBe('1.5')
            ->and($setting->expires_at)->toBeInstanceOf(Carbon::class)
            ->and($setting->expires_at?->getTimestamp())->toBe(FakeClock::T0 + 60);
    }
});

it('keeps the instant of an end time written in another time zone than the app\'s, as exemptions do', function (): void {
    config()->set('app.timezone', 'America/New_York');
    date_default_timezone_set('America/New_York');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0, 'America/New_York'));
    RuleSchema::migrate();

    try {
        Setting::create(['name' => SettingName::BypassAll, 'value' => '1', 'expires_at' => Carbon::createFromTimestamp(FakeClock::T0 + 60, 'Asia/Tokyo')]);
        $readBack = Setting::query()->firstOrFail()->expires_at?->getTimestamp();
    } finally {
        date_default_timezone_set('UTC');
    }

    expect($readBack)->toBe(FakeClock::T0 + 60);
});
