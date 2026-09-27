<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Events\StorageUnavailable;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-012 AC-1 to AC-8: the failure policy (ADR-0010, ADR-0020 point 1,
| ADR-0021). The clock starts at T0, the cooldown is the default 10 seconds,
| the events are faked, and reports are recorded.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Event::fake([StorageUnavailable::class, RateLimitWouldBlock::class]);
    Exceptions::fake();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Moves the clock to T0 plus some seconds.
 */
function atSecond(int $seconds): void
{
    test()->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + $seconds));
}

/**
 * Every reported exception so far, as its class and message.
 *
 * @return list<array{class-string<Throwable>, string}>
 */
function policyReports(): array
{
    return array_map(fn (Throwable $exception): array => [$exception::class, $exception->getMessage()], Exceptions::reported());
}

/**
 * The StorageUnavailable events dispatched so far, in order.
 *
 * @return list<StorageUnavailable>
 */
function storageEvents(): array
{
    return Event::dispatched(StorageUnavailable::class)->map(fn (array $arguments): StorageUnavailable => $arguments[0])->values()->all();
}

/**
 * The status and the Retry-After of a response.
 *
 * @param  TestResponse<Response>  $response
 * @return array{int, ?string}
 */
function statusAndRetry(TestResponse $response): array
{
    return [$response->status(), $response->headers->get('Retry-After')];
}

/**
 * A store that is down, in place of the app's counter store.
 */
function storeDown(): FailingStore
{
    $store = FailingStore::down();
    app()->instance(LimiterStore::class, $store);

    return $store;
}

it('AC-1: lets a fail-open rule\'s request through on a failing store, reports once, and skips the store until the cooldown ends', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $store = storeDown();

    $first = $this->get('api/x');
    $afterFirst = [$store->attempts, count(policyReports()), count(storageEvents())];
    $during = [];

    foreach ([3, 6, 9] as $seconds) {
        atSecond($seconds);
        $during[] = $this->get('api/x');
    }

    $afterCooldown = [$store->attempts, count(policyReports()), count(storageEvents())];
    atSecond(10);
    $this->get('api/x');

    expect([$first->status(), Throttling::headers($first)])->toBe([200, []])
        ->and($afterFirst)->toBe([1, 1, 1])
        ->and(array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $during))->toBe([[200, []], [200, []], [200, []]])
        // No store call, no report and no event during the cooldown.
        ->and($afterCooldown)->toBe([1, 1, 1])
        // At T0 + 10 the store is tried again, still fails, and starts another cooldown.
        ->and([$store->attempts, count(policyReports()), count(storageEvents())])->toBe([2, 2, 2])
        ->and(policyReports()[0])->toBe([StorageUnavailableException::class, FailingStore::DOWN]);
});

it('AC-1: calls the store and reports on every request with the cooldown off', function (): void {
    config()->set('dynamic-throttle.failure.cooldown_seconds', 0);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $store = storeDown();

    $statuses = [$this->get('api/x')->status(), $this->get('api/x')->status(), $this->get('api/x')->status()];

    expect($statuses)->toBe([200, 200, 200])
        ->and([$store->attempts, count(policyReports()), count(storageEvents())])->toBe([3, 3, 3]);
});

it('AC-2: answers 503 with Retry-After for a fail-closed rule on a failing store, and at once during the cooldown', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed, 'max_attempts' => 5]);
    Throttling::route('api/x');
    $store = storeDown();

    $first = $this->get('api/x');
    atSecond(3);
    $during = $this->get('api/x');

    expect(statusAndRetry($first))->toBe([503, '10'])
        ->and(statusAndRetry($during))->toBe([503, '7'])
        ->and($store->attempts)->toBe(1)
        ->and(policyReports())->toBe([[StorageUnavailableException::class, FailingStore::DOWN]]);
});

it('AC-2: renders the 503 through the app\'s handler, as JSON for a JSON request', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    storeDown();

    $response = $this->getJson('api/x');

    expect(statusAndRetry($response))->toBe([503, '10'])
        ->and($response->headers->get('Content-Type'))->toBe('application/json')
        ->and($response->json())->toHaveKey('message');
});

it('AC-2: answers 503 when the store fails on a fail-open rule evaluated before the fail-closed one', function (): void {
    Throttling::rule(['max_attempts' => 5, 'priority' => 1]);
    Throttling::rule(['on_failure' => FailurePolicy::Closed, 'max_attempts' => 5, 'priority' => 2]);
    Throttling::route('api/x');
    $store = storeDown();

    $response = $this->get('api/x');

    // The fail-closed rule never reached the store: the first failure marked it down.
    expect(statusAndRetry($response))->toBe([503, '10'])
        ->and($store->attempts)->toBe(1);
});

it('AC-3: answers 503 for a fail-closed rule whose resolver throws, and releases the count made before it', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 5, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'on_failure' => FailurePolicy::Closed, 'priority' => 2]);
    Throttling::route('api/login');
    Throttling::route('api/other');

    $login = $this->get('api/login');
    $other = $this->get('api/other');

    // No cooldown runs for an identity, so Retry-After is the whole cooldown.
    expect(statusAndRetry($login))->toBe([503, '10'])
        // Released: the other request is the first one the api rule counts.
        ->and(Throttling::headers($other))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4'])
        ->and(policyReports())->toBe([[IdentityUnavailableException::class, 'The identity for custom:tenant could not be resolved: The tenant lookup failed.']])
        ->and(storageEvents())->toBe([]);
});

it('AC-4: never answers 503 from a dry-run fail-closed rule', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    storeDown();

    expect($this->get('api/x')->status())->toBe(200);
});

it('AC-4: never answers 503 to a client exempt from the fail-closed rule', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Exemption::forIp('203.0.113.0/24')->save();
    Throttling::route('api/x');
    storeDown();

    $exempt = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('api/x');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('api/x');

    expect([$exempt->status(), $other->status()])->toBe([200, 503]);
});

it('AC-4: never answers 503 to a guest from a fail-closed user rule that skips guests', function (): void {
    Throttling::rule(['scope' => Scope::User, 'on_missing_identity' => MissingIdentity::Skip, 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    storeDown();

    $guest = $this->get('api/x');
    $user = $this->actingAs(new TestUser(['id' => 1]))->get('api/x');

    expect([$guest->status(), $user->status()])->toBe([200, 503]);
});

it('AC-4: never answers 503 from a fail-closed rule over the cap, even when the store could not count the rule before it', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    Throttling::rule(['max_attempts' => 5, 'priority' => 1]);
    Throttling::rule(['on_failure' => FailurePolicy::Closed, 'max_attempts' => 5, 'priority' => 2]);
    Throttling::route('api/x');
    storeDown();

    // The fail-open rule takes the one slot although its count failed, as it would have with the store up.
    expect($this->get('api/x')->status())->toBe(200);
});

it('AC-4: keeps the 429 of an earlier rule, and never runs the fail-closed rule after it', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 1, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'on_failure' => FailurePolicy::Closed, 'priority' => 2]);
    Throttling::route('api/login');
    Throttling::route('api/other');

    $this->get('api/other');
    $login = $this->get('api/login');

    // The tenant rule never ran, so its resolver never failed.
    expect($login->status())->toBe(429)
        ->and(policyReports())->toBe([]);
});

it('AC-4: never answers 503 without a manifest, since then no rule is known (ADR-0010 point 3)', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    storeDown();
    Cache::forget(app(KeyBuilder::class)->manifest());

    expect($this->get('api/x')->status())->toBe(200);
});

it('AC-5: answers 503 for a fail-closed rule when the store cannot be built, lets a fail-open rule\'s request through, and reports once per cooldown', function (): void {
    Throttling::rule(['pattern' => 'api/closed', 'on_failure' => FailurePolicy::Closed]);
    Throttling::rule(['pattern' => 'api/open']);
    Throttling::route('api/closed');
    Throttling::route('api/open');
    config()->set('dynamic-throttle.driver', 'nope');

    $closed = $this->get('api/closed');
    $open = $this->get('api/open');
    atSecond(5);
    $later = [$this->get('api/closed')->status(), $this->get('api/open')->status()];
    $withinCooldown = policyReports();
    $eventsWithinCooldown = count(storageEvents());
    atSecond(10);
    $this->get('api/open');

    expect([$closed->status(), $open->status()])->toBe([503, 200])
        ->and($later)->toBe([503, 200])
        ->and($withinCooldown)->toBe([[StorageUnavailableException::class, 'The counter store could not be built: Unknown dynamic-throttle.driver "nope": use auto, redis or cache.']])
        ->and($eventsWithinCooldown)->toBe(1)
        ->and(storageEvents()[0]->exceptionClass)->toBe(InvalidArgumentException::class)
        ->and(count(policyReports()))->toBe(2);
});

it('AC-6: keeps the 429 when a release fails, dispatches StorageUnavailable for the release, and skips the store on the next request', function (): void {
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 1, 'priority' => 2]);
    Throttling::route('api/login');
    $store = FailingStore::failingReleases(app(LimiterStore::class));
    app()->instance(LimiterStore::class, $store);

    $this->get('api/login');
    $rejected = $this->get('api/login');
    $attemptsBefore = $store->attempts;
    $next = $this->get('api/login');

    expect($rejected->status())->toBe(429)
        ->and(array_map(fn (StorageUnavailable $event): array => [$event->message, $event->release], storageEvents()))->toBe([[FailingStore::RELEASE_FAILED, true]])
        ->and($store->releases)->toBe(1)
        // The cooldown started with the failed release: no attempt, and the fail-open rules let it through.
        ->and([$store->attempts, $next->status()])->toBe([$attemptsBefore, 200]);
});

it('AC-7: carries the error\'s class and message, whether it was a release, and the cooldown, and survives serialize()', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    storeDown();

    $this->get('api/x');
    $events = storageEvents();

    expect($events)->toHaveCount(1)
        ->and(get_object_vars($events[0]))->toBe([
            'exceptionClass' => StorageUnavailableException::class,
            'message' => FailingStore::DOWN,
            'release' => false,
            'cooldownSeconds' => 10,
        ])
        // A queued listener gets the event back from serialize() (decision 3).
        ->and(unserialize(serialize($events[0])))->toEqual($events[0]);
});

it('AC-7: dispatches StorageUnavailable when the kernel terminates the request, after the response is built', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    storeDown();
    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/x');

    $response = $kernel->handle($request);
    $afterHandle = storageEvents();
    $kernel->terminate($request, $response);

    expect($response->getStatusCode())->toBe(200)
        ->and($afterHandle)->toBe([])
        ->and(storageEvents())->toHaveCount(1);
});

it('AC-7: reports a StorageUnavailable listener that throws, and keeps the response', function (): void {
    // Dispatched for real here, so the listener below runs.
    Event::except(StorageUnavailable::class);
    Event::listen(StorageUnavailable::class, fn (): never => throw new RuntimeException('The listener failed.'));
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    storeDown();

    $response = $this->get('api/x');

    expect(statusAndRetry($response))->toBe([503, '10'])
        ->and(policyReports())->toBe([
            [StorageUnavailableException::class, FailingStore::DOWN],
            [RuntimeException::class, 'The listener failed.'],
        ]);
});

it('AC-8: reports a failing resolver once per cooldown', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant']);
    Throttling::route('api/x');

    foreach ([0, 2, 4, 6, 9] as $seconds) {
        atSecond($seconds);
        $this->get('api/x');
    }

    $withinCooldown = count(policyReports());
    atSecond(10);
    $this->get('api/x');

    expect($withinCooldown)->toBe(1)
        ->and(count(policyReports()))->toBe(2);
});

it('AC-8: reports two causes of the same class once each, since a cause is where it was thrown', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    DynamicThrottle::scope('plan', fn (): string => throw new RuntimeException('The plan lookup failed.'));
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'priority' => 1]);
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'plan', 'priority' => 2]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');

    expect(array_map(fn (array $report): string => $report[1], policyReports()))->toBe([
        'The identity for custom:tenant could not be resolved: The tenant lookup failed.',
        'The identity for custom:plan could not be resolved: The plan lookup failed.',
    ]);
});

it('starts each test without a cooldown: fake() counts on its own store after an outage', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    storeDown();
    $this->get('api/x');

    DynamicThrottle::fake();

    expect(Throttling::headers($this->get('api/x')))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4']);
});
