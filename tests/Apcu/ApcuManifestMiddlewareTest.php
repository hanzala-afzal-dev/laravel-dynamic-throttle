<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Tests\Support\CountingCacheStore;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;
use Composer\InstalledVersions;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(TestCase::class);

/*
| SPEC-028 through the middleware, with APCu itself. The provider gives the
| manifest's read path L1 when APCu is enabled, refresh_seconds is above 0
| and fake() is off.
*/

beforeEach(function (): void {
    if (! ApcuManifestCache::available()) {
        $this->markTestSkipped('APCu is off. Run composer test:apcu.');
    }

    apcu_clear_cache();
    Throttling::prepare();
    // Throttling reads the manifest on every request; L1 needs refresh_seconds above 0.
    config()->set('dynamic-throttle.manifest.refresh_seconds', 2);
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Makes a store that counts its reads the package's cache store, which holds
 * the manifest and the counters.
 */
function countingPackageStore(): CountingCacheStore
{
    $store = new CountingCacheStore;
    Cache::extend('counting', fn (): Repository => Cache::repository($store));
    config()->set('cache.stores.counting', ['driver' => 'counting']);
    config()->set('dynamic-throttle.store', 'counting');

    return $store;
}

/**
 * As the next PHP-FPM request of the server starts: nothing in the process's
 * memory, and the request's services built again. APCu keeps what was stored.
 */
function nextPhpFpmRequest(): void
{
    ManifestRepository::flushState();
    app()->forgetScopedInstances();
    app()->instance(ProcessFailureMemory::class, new ProcessFailureMemory);
}

/**
 * The key the provider keeps this app's entry under: the app's base path, and the
 * commit Composer installed, else the package's version (AC-7).
 */
function providerManifestKey(): string
{
    $version = InstalledVersions::getReference(DynamicThrottleServiceProvider::PACKAGE) ?? InstalledVersions::getPrettyVersion(DynamicThrottleServiceProvider::PACKAGE);

    return app(KeyBuilder::class)->localManifest(hash('xxh128', app()->basePath()), (string) $version);
}

it('AC-2: fills L1 through a request, so the next PHP-FPM request reads nothing from the store', function (): void {
    $store = countingPackageStore();
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $store->resetReads();

    $first = $this->get('api/x');
    nextPhpFpmRequest();
    $second = $this->get('api/x');

    expect([Throttling::headers($first)['X-RateLimit-Remaining'] ?? null, Throttling::headers($second)['X-RateLimit-Remaining'] ?? null])
        ->toBe(['4', '3'])
        ->and($store->reads('dt:m'))->toBe(1);
});

it('AC-5: answers 503 from L1\'s fail-closed rule while the cache store fails, with L0 empty', function (): void {
    Exceptions::fake();
    $store = countingPackageStore();
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    $healthy = $this->get('api/x');

    // The cache store goes down, for the manifest and the counters, after L1's entry stopped being fresh.
    nextPhpFpmRequest();
    $store->failing = true;
    app()->instance(LimiterStore::class, FailingStore::down());
    $this->travel(3)->seconds();
    $response = $this->get('api/x');

    expect([$healthy->status(), $response->status()])->toBe([200, 503]);
});

it('AC-7: keys the entry by the app\'s base path and the commit Composer installed', function (): void {
    Throttling::rule();
    Throttling::route('api/x');

    $this->get('api/x');

    expect(ManifestSnapshot::fromArray(apcu_fetch(providerManifestKey()))?->version)->toBeInt();
});

it('AC-14: builds from L1 only the rules a request matches, and reports a matched rule it cannot build', function (): void {
    Exceptions::fake();
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 5]);
    Throttling::rule(['pattern' => 'web/*', 'max_attempts' => 5]);
    Throttling::route('api/x');
    Throttling::route('web/x');
    $this->get('api/x');

    // Something other than the package spoils the web rule's JSON in L1.
    $entry = apcu_fetch(providerManifestKey());
    $entry['rules'][array_search('web/*', $entry['index']['patterns'], true)] = '{"id": "2"';
    apcu_store(providerManifestKey(), $entry);
    nextPhpFpmRequest();
    $api = $this->get('api/x');
    nextPhpFpmRequest();
    $web = $this->get('web/x');

    expect([$api->status(), Throttling::headers($api)['X-RateLimit-Remaining'] ?? null])->toBe([200, '3'])
        ->and([$web->status(), Throttling::headers($web)])->toBe([200, []]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (InvalidManifest $exception): bool => str_starts_with($exception->getMessage(), 'A rule is not JSON'));
});

it('AC-8: writes nothing to APCu, and reads the store on every request, with refresh_seconds 0', function (): void {
    config()->set('dynamic-throttle.manifest.refresh_seconds', 0);
    $store = countingPackageStore();
    Throttling::rule();
    Throttling::route('api/x');
    $store->resetReads();

    $this->get('api/x');
    $this->get('api/x');

    expect(apcu_cache_info(true)['num_entries'])->toBe(0)
        ->and($store->reads('dt:m'))->toBe(2);
});

it('AC-8: writes nothing to APCu under DynamicThrottle::fake(), even with refresh_seconds above 0', function (): void {
    DynamicThrottle::fake();
    config()->set('dynamic-throttle.manifest.refresh_seconds', 2);
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $statuses = [$this->get('api/x')->status(), $this->get('api/x')->status()];

    expect($statuses)->toBe([200, 429])
        ->and(apcu_cache_info(true)['num_entries'])->toBe(0);
});
