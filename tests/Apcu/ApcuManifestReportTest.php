<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestUnavailable;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\OutageCache;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(TestCase::class);

/*
| SPEC-029 AC-1, a gap the runbook's test found. On PHP-FPM, each request
| starts with an empty process, so a manifest the cache could not give was
| reported about once per refresh_seconds per server, or on every request
| without a manifest to fall back on, not at most once per 30 seconds. With
| APCu, the server's workers share when it was last reported, as they share
| the counter store's cooldown (SPEC-012).
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
    Exceptions::fake();
    $this->redis = OutageCache::asPackageStore();
    Throttling::rule(['pattern' => 'api/orders']);
    Throttling::route('api/orders');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Sends two requests at T0 plus each of the seconds, each as a new PHP-FPM
 * request of the server: nothing in the process's memory, and the request's
 * services built again. APCu keeps what was stored.
 *
 * @param  list<int>  $seconds
 */
function phpFpmRequestsAt(array $seconds): void
{
    foreach ($seconds as $second) {
        test()->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + $second));

        for ($request = 0; $request < 2; $request++) {
            ManifestRepository::flushState();
            app()->forgetScopedInstances();
            app()->instance(ProcessFailureMemory::class, new ProcessFailureMemory);
            test()->get('api/orders');
        }
    }
}

function manifestReports(): int
{
    return count(array_filter(Exceptions::reported(), fn (Throwable $exception): bool => $exception instanceof ManifestUnavailable));
}

it('SPEC-029 AC-1: reports a manifest the cache cannot give at most once per 30 seconds per server, with the last one to fall back on', function (): void {
    phpFpmRequestsAt([0]);
    $this->redis->down = true;

    phpFpmRequestsAt(range(1, 30));
    $withinThirtySeconds = manifestReports();
    phpFpmRequestsAt([33]);

    expect($withinThirtySeconds)->toBe(1)
        ->and(manifestReports())->toBe(2);
});

it('SPEC-029 AC-1: reports a manifest the cache cannot give at most once per 30 seconds per server, with none to fall back on', function (): void {
    $this->redis->down = true;

    phpFpmRequestsAt(range(0, 29));
    $withinThirtySeconds = manifestReports();
    phpFpmRequestsAt([30]);

    expect($withinThirtySeconds)->toBe(1)
        ->and(manifestReports())->toBe(2);
});
