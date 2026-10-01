<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| SPEC-013 AC-18 to AC-20: dynamic-throttle:status shows what servers apply,
| from the manifest in the cache store, and checks the config (ADR-0020 point
| 1). It exits 1 only for an invalid config, and never writes anything. The
| clock stands at 2027-01-01 00:00:00 UTC.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-18: shows the kill switch, the driver, the manifest, and each setting with its end time and the time left', function (): void {
    Throttling::rule();
    Exemption::forIp('10.0.0.0/8')->save();
    Settings::bypass(until: Settings::at('2027-01-01 00:30:00'));
    Settings::scale('2');

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('Enabled')
        ->expectsOutputToContain('cache, store array')
        ->expectsOutputToContain('version 1798761600003: 1 rule, 1 exemption')
        ->expectsOutputToContain('on until 2027-01-01 00:30:00 UTC, 30 minutes left')
        ->expectsOutputToContain('factor 2, with no end time')
        ->doesntExpectOutputToContain('no compile has written')
        ->assertExitCode(0);
});

it('AC-18: shows the kill switch when it is off', function (): void {
    config()->set('dynamic-throttle.enabled', false);

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('no: DYNAMIC_THROTTLE_ENABLED is off')
        ->assertExitCode(0);
});

it('AC-18: shows a setting that has ended since the compile', function (): void {
    Settings::bypass(until: Settings::at('2027-01-01 00:30:00'));
    $this->travelTo(Carbon::parse('2027-01-01 00:31:00', 'UTC'));

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('ended at 2027-01-01 00:30:00 UTC')
        ->assertExitCode(0);
});

it('AC-18: shows a missing manifest, and still exits 0', function (): void {
    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('missing: run php artisan dynamic-throttle:compile')
        ->assertExitCode(0);
});

it('AC-18: shows a manifest it cannot read, and still exits 0', function (): void {
    Cache::forever(app(KeyBuilder::class)->manifest(), '{"format": 3');

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('cannot be read: The manifest is not JSON')
        ->assertExitCode(0);
});

it('AC-18: shows a setting saved but not compiled yet, and still exits 0', function (): void {
    Throttling::rule();
    Settings::insert(['expires_at' => Carbon::parse('2027-01-01 00:30:00', 'UTC')->toDateTimeString()]);

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('The database holds settings that no compile has written yet: run php artisan dynamic-throttle:compile.')
        ->assertExitCode(0);
});

it('AC-18: shows a database it cannot read, and still exits 0', function (): void {
    Throttling::rule();
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/dynamic-throttle.sqlite', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'unreachable');

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('The database cannot be read:')
        ->assertExitCode(0);
});

it('AC-19: prints the config error, and exits 1, so a deploy script can stop', function (string $key, string $value, string $message): void {
    config()->set("dynamic-throttle.{$key}", $value);

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain("The config is invalid: {$message}")
        ->assertExitCode(1);
})->with([
    'an unknown driver' => ['driver', 'nope', 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.'],
    'an undefined store' => ['store', 'missing', 'Cache store [missing] is not defined.'],
    'an invalid prefix' => ['prefix', 'bad prefix!', 'The key prefix must be at most 32 characters from A-Z, a-z, 0-9, ":", "_", "." and "-".'],
]);

it('AC-20: writes nothing, and compiles and rebuilds nothing, even with the manifest missing', function (): void {
    Throttling::rule();
    Cache::forget(app(KeyBuilder::class)->manifest());
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|create|drop|alter)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('dynamic-throttle:status')->assertExitCode(0);
    app()->terminate();

    expect(Cache::get(app(KeyBuilder::class)->manifest()))->toBeNull()
        ->and($writes)->toBe([]);
});
