<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;

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

it('AC-2: shows a value JSON cannot hold as its type, and hides a key that looks secret', function (): void {
    config()->set('dynamic-throttle.dashboard.extra', fn (): int => 1);
    config()->set('dynamic-throttle.redis_password', 'hunter2');
    config()->set('dynamic-throttle.telemetry.api_token', 'abc');
    config()->set('dynamic-throttle.dashboard.client_secret', ['a' => 'b']);

    $response = DashboardApi::send($this, 'GET', 'config', user: DashboardApi::viewer());

    expect([
        $response->json('config.dashboard.extra'),
        $response->json('config.redis_password'),
        $response->json('config.telemetry.api_token'),
        $response->json('config.dashboard.client_secret'),
        str_contains((string) $response->getContent(), 'hunter2'),
    ])->toBe(['(Closure)', '(hidden)', '(hidden)', '(hidden)', false]);
});
