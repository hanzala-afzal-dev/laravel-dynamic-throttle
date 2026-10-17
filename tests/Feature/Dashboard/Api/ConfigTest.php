<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-031 AC-1 and AC-2: the effective configuration the Settings page shows.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-1: answers a viewer the driver, the manifest store, the config as the app set it, the custom scopes and strict mode', function (): void {
    config()->set('dynamic-throttle.driver', 'cache');
    config()->set('dynamic-throttle.store', 'array');
    config()->set('dynamic-throttle.prefix', 'shop:');
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
    DynamicThrottle::strict();

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([
        $response->status(),
        $response->json('driver'),
        $response->json('driver_error'),
        $response->json('manifest_store'),
        $response->json('config.prefix'),
        $response->json('config.dashboard.middleware'),
        $response->json('config.scopes.ip.ipv6_prefix'),
        $response->json('scopes'),
        $response->json('strict'),
    ])->toBe([200, 'cache, store array', null, 'array', 'shop:', ['web'], 64, ['tenant'], true]);
});

it('AC-1: gives the default cache store\'s name when the package names none', function (): void {
    config()->set('dynamic-throttle.store');
    config()->set('cache.default', 'array');

    expect(DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer())->json('manifest_store'))->toBe('array');
});

it('AC-2: answers a driver it cannot build with its error, and still the rest', function (): void {
    config()->set('dynamic-throttle.driver', 'nope');

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('driver'), $response->json('driver_error'), $response->json('config.driver')])
        ->toBe([200, null, 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.', 'nope']);
});

it('AC-2: shows a value JSON cannot hold as its type, in a key the package defines', function (): void {
    config()->set('dynamic-throttle.except', [fn (): int => 1, 'horizon/*']);

    expect(DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer())->json('config.except'))->toBe(['(Closure)', 'horizon/*']);
});

it('AC-7: shows a key the package does not define as unused, never its value, at any depth', function (): void {
    config()->set('dynamic-throttle.redis_password', 'hunter2');
    config()->set('dynamic-throttle.api_key', 'sk-123');
    config()->set('dynamic-throttle.dsn', 'redis://user:hunter2@cache.internal');
    config()->set('dynamic-throttle.telemetry.webhook', ['url' => 'https://hooks.example/T0/B0/xyz']);
    config()->set('dynamic-throttle.dashboard.client_secret', ['a' => 'b']);

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([
        $response->json('config.redis_password'),
        $response->json('config.api_key'),
        $response->json('config.dsn'),
        $response->json('config.telemetry.webhook'),
        $response->json('config.dashboard.client_secret'),
        $response->json('config.telemetry.sample_rate'),
        $response->json('config.dashboard.middleware'),
        preg_match('/hunter2|sk-123|xyz/', (string) $response->getContent()),
    ])->toBe(['(not used by the package)', '(not used by the package)', '(not used by the package)', '(not used by the package)', '(not used by the package)', 1, ['web'], 0]);
});

it('AC-8: answers a key prefix it cannot build keys from with its error, and still the rest', function (): void {
    config()->set('dynamic-throttle.prefix', 'my app:');

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('driver'), $response->json('config.prefix')])->toBe([200, null, 'my app:'])
        ->and($response->json('driver_error'))->toContain('prefix');
});

it('AC-8: names only the class of an error that is not a config mistake, and reports it', function (): void {
    Exceptions::fake();
    Cache::extend('exploding', fn (): never => throw new RuntimeException('redis://:hunter2@10.0.0.5 refused the connection'));
    config()->set('cache.stores.boom', ['driver' => 'exploding']);
    config()->set('dynamic-throttle.driver', 'cache');
    config()->set('dynamic-throttle.store', 'boom');

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('driver'), $response->json('driver_error')])->toBe([200, null, 'The counter driver could not be built: RuntimeException. The app\'s log has the details.'])
        ->and(str_contains((string) $response->getContent(), 'hunter2'))->toBeFalse();
    Exceptions::assertReported(RuntimeException::class);
});

it('AC-8: answers a deeply nested value, cut at a depth', function (): void {
    $nested = 'bottom';

    foreach (range(1, 600) as $level) {
        $nested = [$nested];
    }

    config()->set('dynamic-throttle.except', $nested);
    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([$response->status(), str_contains((string) $response->getContent(), '(nested too deep)')])->toBe([200, true]);
});
