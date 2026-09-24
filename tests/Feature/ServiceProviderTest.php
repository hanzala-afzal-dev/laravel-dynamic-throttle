<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/*
| Phase 0 smoke tests: the package boots inside a Laravel application.
*/

it('boots the service provider', function (): void {
    expect(app()->providerIsLoaded(DynamicThrottleServiceProvider::class))->toBeTrue();
});

it('merges the package config under the dynamic-throttle key', function (): void {
    expect(config('dynamic-throttle.enabled'))->toBeTrue();
});

it('publishes the config file with the dynamic-throttle-config tag', function (): void {
    // Checks the mapping instead of copying the file: a copy in Testbench's shared
    // config folder breaks other workers under --parallel. scripts/verify-install.sh
    // publishes for real into a fresh Laravel app.
    $paths = ServiceProvider::pathsToPublish(DynamicThrottleServiceProvider::class, 'dynamic-throttle-config');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/dynamic-throttle.php'))
        ->and(array_values($paths))->toBe([config_path('dynamic-throttle.php')]);
});

it('reports its version and kill switch in php artisan about', function (): void {
    Artisan::call('about', ['--json' => true]);

    /** @var array<string, array<string, mixed>> $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about)->toHaveKey('dynamic_throttle')
        ->and($about['dynamic_throttle'])->toHaveKeys(['version', 'enabled'])
        ->and($about['dynamic_throttle']['enabled'])->toBeTrue();
});

/*
| SPEC-005 AC-6: the provider merges with ConfigMerger (ADR-0020 point 10).
*/

it('AC-6: gives a config published before a section existed that section\'s defaults', function (): void {
    config()->set('dynamic-throttle', [
        'enabled' => true,
        'driver' => 'auto',
        'store' => null,
        'redis_connection' => null,
        'prefix' => 'app:',
    ]);

    app()->register(DynamicThrottleServiceProvider::class, force: true);

    expect(config('dynamic-throttle.prefix'))->toBe('app:')
        ->and(config()->has('dynamic-throttle.database.connection'))->toBeTrue()
        ->and(config('dynamic-throttle.database.connection'))->toBeNull();
});

it('AC-6: fills an empty section with its defaults, which mergeConfigFrom would not', function (): void {
    config()->set('dynamic-throttle', ['database' => []]);

    app()->register(DynamicThrottleServiceProvider::class, force: true);

    expect(config()->has('dynamic-throttle.database.connection'))->toBeTrue()
        ->and(config('dynamic-throttle.prefix'))->toBe('dt:');
});

it('AC-8: publishes the migrations and never loads them', function (): void {
    $package = __DIR__.'/../../database/migrations';
    $paths = ServiceProvider::pathsToPublish(DynamicThrottleServiceProvider::class, 'dynamic-throttle-migrations');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath($package))
        ->and(array_values($paths))->toBe([database_path('migrations')])
        ->and(array_map(realpath(...), app('migrator')->paths()))->not->toContain(realpath($package));
});
