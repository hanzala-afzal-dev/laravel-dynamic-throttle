<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Database;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-037, issue #205: the database check, which errs when the package's database
| cannot be reached or its rules table is missing, and warns about each other table
| that is missing, with what stops without it. Every check passes before each test
| changes one thing (Health::passing()).
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const DATABASE_FIX = "Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, then run php artisan migrate.";

/** What stops without each table but the rules', which errs. */
const DATABASE_TABLES = [
    'exemptions' => ['dynamic_throttle_exemptions', 'the manifest has no exemptions'],
    'settings' => ['dynamic_throttle_settings', 'bypass and scale cannot be turned on'],
    'audit log' => ['dynamic_throttle_audit_log', 'the dashboard and the commands can change nothing, since each change writes an audit entry'],
    'hourly metrics' => ['dynamic_throttle_metrics_hourly', 'the traffic numbers are never stored'],
];

function dropPackageTable(string $table): void
{
    Schema::disableForeignKeyConstraints();
    Schema::drop($table);
    Schema::enableForeignKeyConstraints();
}

it('AC-1: is ok, naming the connection, with every table in place', function (): void {
    expect(Health::check('database'))->toBe(['ok', "The database answers on connection testing, and the package's tables exist."]);
});

it('AC-2: errs when the connection is refused, with the root cause\'s error, skips compile, and the command exits 1', function (): void {
    Database::down();

    [$exitCode] = Health::report();

    expect(Health::check('database'))->toBe(['error', 'The database cannot be reached on connection '.Database::CONNECTION.', so no rule can be saved or compiled, and servers keep the last manifest: '.Database::REFUSED])
        ->and(Health::check('compile'))->toBe(['skipped', 'The database cannot be read: see the database check.'])
        ->and($exitCode)->toBe(1);
});

it('AC-3: errs without the rules table, and skips compile', function (): void {
    dropPackageTable('dynamic_throttle_rules');

    expect(Health::check('database'))->toBe(['error', 'The dynamic_throttle_rules table does not exist, so no rule can be saved, and the compile fails. '.DATABASE_FIX])
        ->and(Health::check('compile'))->toBe(['skipped', 'The database cannot be read: see the database check.']);
});

it('AC-4: warns without another table, naming what stops without it', function (string $table, string $stops): void {
    dropPackageTable($table);

    expect(Health::check('database'))->toBe(['warning', "The {$table} table does not exist, so {$stops}. ".DATABASE_FIX]);
})->with(DATABASE_TABLES);

it('AC-4: names each missing table, in the migrations\' order, and errs when the rules table is one', function (): void {
    dropPackageTable('dynamic_throttle_audit_log');
    dropPackageTable('dynamic_throttle_rules');

    expect(Health::check('database'))->toBe(['error', 'The dynamic_throttle_rules table does not exist, so no rule can be saved, and the compile fails. '
        .'The dynamic_throttle_audit_log table does not exist, so the dashboard and the commands can change nothing, since each change writes an audit entry. '.DATABASE_FIX]);
});

it('AC-4: is ok without the hourly metrics table while telemetry is off', function (): void {
    config()->set('dynamic-throttle.telemetry.enabled', false);
    dropPackageTable('dynamic_throttle_metrics_hourly');

    expect(Health::check('database')[0])->toBe('ok');
});

it('AC-4: warns without the links table only while links are on', function (): void {
    dropPackageTable('dynamic_throttle_links');
    $off = Health::check('database');
    config()->set('dynamic-throttle.dashboard.links', true);

    expect($off[0])->toBe('ok')
        ->and(Health::check('database'))->toBe(['warning', 'The dynamic_throttle_links table does not exist, so links can be neither made nor used. '.DATABASE_FIX]);
});
