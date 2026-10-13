<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestUnavailable;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-029 AC-2: the runbook's "The manifest was evicted"
| (docs/runbook.md#the-manifest-was-evicted). The test follows the section's
| steps, and checks what the section says you will see. /api/orders has a rule
| of 2 requests a minute, and the cache loses the manifest. The clock starts
| at T0.
*/

const MISSING_MANIFEST = 'The manifest is missing from the cache. One request will rebuild it after its response.';

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Exceptions::fake();
    Throttling::rule(['pattern' => 'api/orders', 'max_attempts' => 2]);
    Throttling::route('api/orders');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The cache loses the manifest, as when Redis evicts it, restarts empty, or is flushed.
 */
function evictManifest(): void
{
    Cache::forget(app(KeyBuilder::class)->manifest());
}

function manifestCached(): bool
{
    return is_string(Cache::get(app(KeyBuilder::class)->manifest()));
}

/**
 * The status and the rate-limit headers of each response.
 *
 * @param  list<TestResponse<Response>>  $responses
 * @return list<array{int, array<string, string>}>
 */
function evictedResponses(array $responses): array
{
    return array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $responses);
}

/**
 * Every reported exception so far, as its class and message.
 *
 * @return list<array{class-string<Throwable>, string}>
 */
function evictedReports(): array
{
    return array_map(fn (Throwable $exception): array => [$exception::class, $exception->getMessage()], Exceptions::reported());
}

/**
 * Sends a request as PHP-FPM and Octane do, where what an earlier request
 * registered to run after its response never runs again. Laravel's test app
 * keeps those callbacks, and would run each after every later request.
 *
 * @return TestResponse<Response>
 */
function ownRequest(string $uri): TestResponse
{
    $response = test()->get($uri);
    (fn (): array => $this->terminatingCallbacks = [])->call(app());

    return $response;
}

/**
 * The package's database is down: its tables are on a connection that cannot connect.
 */
function packageDatabaseDown(): void
{
    config()->set('database.connections.down', ['driver' => 'sqlite', 'database' => '/nonexistent/dynamic-throttle.sqlite']);
    config()->set('dynamic-throttle.database.connection', 'down');
}

it('AC-2: keeps applying the rules in a process that had read the manifest, and rebuilds it after a response', function (): void {
    $this->get('api/orders');
    evictManifest();

    $during = $this->get('api/orders');

    expect(evictedResponses([$during]))->toBe([[200, ['X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '0']]])
        ->and(manifestCached())->toBeTrue()
        ->and(evictedReports())->toBe([[ManifestUnavailable::class, MISSING_MANIFEST]]);
});

it('AC-2: applies no rule to the first request of a process that had not read it, rebuilds it after that response, and limits the next request', function (): void {
    evictManifest();
    ManifestRepository::flushState();

    $first = $this->get('api/orders');
    $next = $this->get('api/orders');

    expect(evictedResponses([$first, $next]))->toBe([
        [200, []],
        [200, ['X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '1']],
    ])->and(evictedReports())->toBe([[ManifestUnavailable::class, MISSING_MANIFEST]]);
});

it('AC-2: says the manifest is missing in status, which exits 0, and errs on it in health, which exits 1', function (): void {
    evictManifest();

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('missing: run php artisan dynamic-throttle:compile')
        ->assertExitCode(0);
    [$exitCode] = Health::report();

    expect($exitCode)->toBe(1)
        ->and(Health::check('manifest'))->toBe(['error', 'The manifest is missing, so no rule applies: run php artisan dynamic-throttle:compile.']);
});

it('AC-2: restores it at once with dynamic-throttle:compile, and then the manifest check is ok', function (): void {
    evictManifest();
    ManifestRepository::flushState();

    $this->artisan('dynamic-throttle:compile')
        ->expectsOutputToContain('Compiled manifest version')
        ->assertExitCode(0);
    $first = $this->get('api/orders');

    expect(Health::status('manifest'))->toBe('ok')
        ->and(evictedResponses([$first]))->toBe([[200, ['X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '1']]]);
});

it('AC-2: reports a rebuild that fails, such as with the database down, tries it again at most once per 30 seconds, and recovers with dynamic-throttle:compile', function (): void {
    evictManifest();
    ManifestRepository::flushState();
    packageDatabaseDown();

    $during = [ownRequest('api/orders'), ownRequest('api/orders'), ownRequest('api/orders')];
    $reportedWithin30Seconds = array_column(evictedReports(), 0);
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 30));
    ownRequest('api/orders');
    $reportedAfter30Seconds = array_slice(array_column(evictedReports(), 0), count($reportedWithin30Seconds));
    $this->artisan('dynamic-throttle:compile')
        ->expectsOutputToContain('Could not compile the manifest')
        ->assertExitCode(1);

    config()->set('dynamic-throttle.database.connection');
    $this->artisan('dynamic-throttle:compile')->assertExitCode(0);
    $after = $this->get('api/orders');

    expect(evictedResponses($during))->toBe(array_fill(0, 3, [200, []]))
        // One request rebuilds, across servers, and no other for 30 seconds.
        ->and($reportedWithin30Seconds)->toBe([ManifestUnavailable::class, ManifestCompileFailed::class])
        ->and($reportedAfter30Seconds)->toBe([ManifestUnavailable::class, ManifestCompileFailed::class])
        ->and(evictedResponses([$after]))->toBe([[200, ['X-RateLimit-Limit' => '2', 'X-RateLimit-Remaining' => '1']]]);
});
