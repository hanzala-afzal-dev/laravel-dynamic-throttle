<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/*
| Issue #206. vendor:publish copies every migration of the package again, each under
| a new date, also those the app has run. So each migration skips itself when its
| table is already there: migrate then creates only the tables a new version adds,
| and never records a copy it skipped, so a rollback never drops a table through one.
*/

const PUBLISHED_LINKS = 'create_dynamic_throttle_links_table.php';

beforeEach(function (): void {
    $this->published = sys_get_temp_dir().'/dynamic-throttle-published-'.bin2hex(random_bytes(6));
});

afterEach(function (): void {
    File::deleteDirectory($this->published);
});

function migratePublished(string $directory): int
{
    return Artisan::call('migrate', ['--path' => $directory, '--realpath' => true]);
}

/**
 * The package's migrations the app has run, by name, in order.
 *
 * @return list<string>
 */
function ranPackageMigrations(): array
{
    return array_values(array_filter(app('migration.repository')->getRan(), fn (string $name): bool => str_contains($name, 'dynamic_throttle')));
}

/**
 * An app on 0.1: it published and ran every migration but the links table's, and has a rule.
 */
function appOnTheFirstRelease(string $directory, ?string $connection = null): void
{
    RuleSchema::publish($directory, '2026_10_29', except: [PUBLISHED_LINKS]);
    migratePublished($directory);
    DB::connection($connection)->table('dynamic_throttle_rules')->insert(['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]);
}

/** @var list<string> the five migrations 0.1 had, as the app published them */
const PUBLISHED_FIRST = [
    '2026_10_29_000001_create_dynamic_throttle_rules_table',
    '2026_10_29_000002_create_dynamic_throttle_exemptions_table',
    '2026_10_29_000003_create_dynamic_throttle_settings_table',
    '2026_10_29_000004_create_dynamic_throttle_audit_log_table',
    '2026_10_29_000005_create_dynamic_throttle_metrics_hourly_table',
];

it('issue #206: migrates the copies a second publish makes: skips those whose table exists, creates the new table, and keeps the data', function (): void {
    appOnTheFirstRelease($this->published);

    // 0.2: publishing again copies all six, under that day's date.
    RuleSchema::publish($this->published, '2026_11_04');
    $code = migratePublished($this->published);

    expect($code)->toBe(0)
        ->and(Schema::hasTable('dynamic_throttle_links'))->toBeTrue()
        ->and(DB::table('dynamic_throttle_rules')->pluck('name')->all())->toBe(['Login'])
        ->and(ranPackageMigrations())->toBe([...PUBLISHED_FIRST, '2026_11_04_000006_create_dynamic_throttle_links_table']);
});

it('issue #206: rolls back only what migrate created, never a table through a copy it skipped', function (): void {
    appOnTheFirstRelease($this->published);
    RuleSchema::publish($this->published, '2026_11_04');
    migratePublished($this->published);

    $code = Artisan::call('migrate:rollback', ['--path' => $this->published, '--realpath' => true]);

    expect($code)->toBe(0)
        ->and(Schema::hasTable('dynamic_throttle_links'))->toBeFalse()
        ->and(DB::table('dynamic_throttle_rules')->pluck('name')->all())->toBe(['Login'])
        ->and(ranPackageMigrations())->toBe(PUBLISHED_FIRST);
});

it('issue #206: migrate:status shows the copies of tables the app has as Skipped, and the new table as Pending', function (): void {
    appOnTheFirstRelease($this->published);
    RuleSchema::publish($this->published, '2026_11_04');

    Artisan::call('migrate:status', ['--path' => $this->published, '--realpath' => true]);
    $status = [];

    foreach (explode("\n", Artisan::output()) as $line) {
        if (preg_match('/(2026_11_04_\d{6}_\w+)\W.*\b(Ran|Skipped|Pending)\s*$/', $line, $match) === 1) {
            $status[$match[1]] = $match[2];
        }
    }

    expect($status)->toBe([
        '2026_11_04_000001_create_dynamic_throttle_rules_table' => 'Skipped',
        '2026_11_04_000002_create_dynamic_throttle_exemptions_table' => 'Skipped',
        '2026_11_04_000003_create_dynamic_throttle_settings_table' => 'Skipped',
        '2026_11_04_000004_create_dynamic_throttle_audit_log_table' => 'Skipped',
        '2026_11_04_000005_create_dynamic_throttle_metrics_hourly_table' => 'Skipped',
        '2026_11_04_000006_create_dynamic_throttle_links_table' => 'Pending',
    ]);
});

it('issue #206: asks the connection the package\'s tables live on whether a table exists', function (): void {
    config()->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'secondary');
    appOnTheFirstRelease($this->published, 'secondary');

    RuleSchema::publish($this->published, '2026_11_04');
    $code = migratePublished($this->published);

    expect($code)->toBe(0)
        ->and(Schema::connection('secondary')->hasTable('dynamic_throttle_links'))->toBeTrue()
        ->and(DB::connection('secondary')->table('dynamic_throttle_rules')->pluck('name')->all())->toBe(['Login'])
        ->and(Schema::hasTable('dynamic_throttle_rules'))->toBeFalse();
});

it('issue #206: migrate:fresh creates each table once, from its oldest copy, with the newer copies in the folder', function (): void {
    RuleSchema::publish($this->published, '2026_10_29', except: [PUBLISHED_LINKS]);
    RuleSchema::publish($this->published, '2026_11_04');

    $code = Artisan::call('migrate:fresh', ['--path' => $this->published, '--realpath' => true]);

    expect($code)->toBe(0)
        ->and(array_map(Schema::hasTable(...), RuleSchema::tables()))->each->toBeTrue()
        ->and(ranPackageMigrations())->toBe([...PUBLISHED_FIRST, '2026_11_04_000006_create_dynamic_throttle_links_table']);
});

it('issue #206: every migration of the package runs on an empty database, and skips itself once it ran, so a new one cannot forget', function (): void {
    $migrations = glob(RuleSchema::MIGRATIONS.'/*.php') ?: [];
    $shouldRun = function () use ($migrations): array {
        $answers = [];

        foreach ($migrations as $file) {
            $migration = require $file;
            $answers[basename($file)] = $migration instanceof Migration && $migration->shouldRun();
        }

        return $answers;
    };

    $before = $shouldRun();
    RuleSchema::migrate();

    expect($before)->toBe(array_fill_keys(array_map(basename(...), $migrations), true))
        ->and($shouldRun())->toBe(array_fill_keys(array_map(basename(...), $migrations), false));
});
