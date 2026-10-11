<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Events\StorageUnavailable;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestUnavailable;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\OutageCache;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;

/*
| SPEC-029 AC-1: the runbook's "Redis is down" (docs/runbook.md#redis-is-down).
| The test follows the section's steps, and checks what the section says you
| will see. One cache store holds the manifest and the counters, as Redis
| usually does, and goes down. /api/orders has a fail-open rule, and /api/login
| a fail-closed one, 5 requests a minute each. The clock starts at T0, and the
| cooldown is the default 10 seconds.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Event::fake([StorageUnavailable::class]);
    Exceptions::fake();
    $this->redis = OutageCache::asPackageStore();
    Throttling::rule(['pattern' => 'api/orders', 'max_attempts' => 5]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 5, 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/orders');
    Throttling::route('api/login');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The status and the rate-limit headers of each response.
 *
 * @param  list<TestResponse<Symfony\Component\HttpFoundation\Response>>  $responses
 * @return list<array{int, array<string, string>}>
 */
function runbookResponses(array $responses): array
{
    return array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $responses);
}

/**
 * Every reported exception so far, as its class and message.
 *
 * @return list<array{class-string<Throwable>, string}>
 */
function runbookReports(): array
{
    return array_map(fn (Throwable $exception): array => [$exception::class, $exception->getMessage()], Exceptions::reported());
}

it('AC-1: lets the fail-open rule\'s requests through without rate-limit headers, and reports the outage once per cooldown, however many requests come', function (): void {
    $before = $this->get('api/orders');
    $this->redis->down = true;
    $during = [];

    for ($second = 0; $second < 10; $second++) {
        $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + $second));
        $during[] = $this->get('api/orders');
        $during[] = $this->get('api/orders');
    }

    $reportsDuringCooldown = runbookReports();
    $eventsDuringCooldown = count(Event::dispatched(StorageUnavailable::class));
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
    $this->get('api/orders');

    $manifest = [ManifestUnavailable::class, 'The manifest could not be read: '.OutageCache::DOWN];
    $counters = [StorageUnavailableException::class, 'The limiter store could not count the request.'];

    expect(Throttling::headers($before))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4'])
        ->and(runbookResponses($during))->toBe(array_fill(0, 20, [200, []]))
        ->and($reportsDuringCooldown)->toBe([$manifest, $counters])
        ->and($eventsDuringCooldown)->toBe(1)
        // The cooldown ended: the store was tried again, failed again, and was reported again.
        // The manifest is reported at most once per 30 seconds.
        ->and(runbookReports())->toBe([$manifest, $counters, $counters])
        ->and(Event::dispatched(StorageUnavailable::class))->toHaveCount(2);
});

it('AC-1: answers 503 with Retry-After on the fail-closed rule\'s route, with the rules the process read before the outage', function (): void {
    $this->get('api/login');
    $this->redis->down = true;

    $first = $this->get('api/login');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 4));
    $later = $this->get('api/login');

    expect([$first->status(), $first->headers->get('Retry-After')])->toBe([503, '10'])
        ->and([$later->status(), $later->headers->get('Retry-After')])->toBe([503, '6']);
});

it('AC-1: applies no rule in a process that starts during the outage, not even the fail-closed one (ADR-0010 point 3)', function (): void {
    $this->redis->down = true;
    ManifestRepository::flushState();

    $login = $this->get('api/login');

    expect([$login->status(), Throttling::headers($login)])->toBe([200, []]);
});

it('AC-1: still exits 0 from dynamic-throttle:status, which prints the driver and says the manifest cannot be read', function (): void {
    $this->redis->down = true;

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('cache, store outage')
        ->expectsOutputToContain('cannot be read: '.OutageCache::DOWN)
        ->assertExitCode(0);
});

it('AC-1: counts requests again, with their headers, once the store is back and the cooldown has ended', function (): void {
    $this->get('api/orders');
    $this->redis->down = true;
    $during = $this->get('api/orders');
    $this->redis->down = false;

    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 5));
    $withinCooldown = $this->get('api/orders');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
    $after = $this->get('api/orders');

    expect(runbookResponses([$during, $withinCooldown, $after]))->toBe([
        [200, []],
        // The process skips the store until its cooldown ends, even though the store is back.
        [200, []],
        // The store kept its data: the request before the outage still counts.
        [200, ['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '3']],
    ]);
});
