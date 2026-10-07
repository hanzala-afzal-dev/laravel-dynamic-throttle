<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\RateLimitExceeded;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Events\StorageUnavailable;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-015 AC-19 and AC-20: RateLimitExceeded, dispatched after the response
| for the rule that answered 429. The clock stands at T0+10, so a limit of 1
| counted in this window lets the client in again after 110 seconds.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return list<RateLimitExceeded>
 */
function exceededEvents(): array
{
    return Event::dispatched(RateLimitExceeded::class)->map(fn (array $arguments): RateLimitExceeded => $arguments[0])->values()->all();
}

it('AC-19: dispatches one RateLimitExceeded for a 429, with the rule, the client\'s hash, the Retry-After and the request', function (): void {
    Event::fake([RateLimitExceeded::class]);
    Throttling::rule(['name' => 'Login', 'pattern' => 'api/login', 'max_attempts' => 1]);
    Throttling::route('api/login');

    $this->get('api/login')->assertOk();
    $this->get('api/login')->assertStatus(429);
    $events = exceededEvents();

    expect($events)->toHaveCount(1)
        ->and(get_object_vars($events[0]))->toBe([
            'ruleId' => '1',
            'ruleName' => 'Login',
            'limit' => 1,
            'windowSeconds' => 60,
            'scope' => Scope::Ip,
            'identityHash' => Identities::hashed('ip:127.0.0.1'),
            'retryAfter' => 110,
            'method' => 'GET',
            'path' => 'api/login',
        ])
        // A queued listener gets it back from serialize().
        ->and(unserialize(serialize($events[0])))->toEqual($events[0]);
});

it('AC-19: dispatches it when the kernel terminates the request, after the 429 is built', function (): void {
    Event::fake([RateLimitExceeded::class]);
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/x');

    $response = $kernel->handle($request);
    $afterHandle = exceededEvents();
    $kernel->terminate($request, $response);

    expect([$response->getStatusCode(), $afterHandle])->toBe([429, []])
        ->and(exceededEvents())->toHaveCount(1);
});

it('AC-19: dispatches it for a rule that answers with its response override, with its scaled limit', function (): void {
    Event::fake([RateLimitExceeded::class]);
    Throttling::rule(['max_attempts' => 1, 'response_override' => ['message' => 'Slow down']]);
    Throttling::route('api/x');
    Settings::scale('2');

    foreach (range(1, 2) as $request) {
        $this->get('api/x')->assertOk();
    }

    $this->get('api/x')->assertStatus(429)->assertJson(['message' => 'Slow down']);

    expect(array_map(fn (RateLimitExceeded $event): array => [$event->ruleId, $event->limit], exceededEvents()))->toBe([['1', 2]]);
});

it('AC-19: dispatches nothing for an allowed request, or for a dry-run rule that would block', function (): void {
    Event::fake([RateLimitExceeded::class]);
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();

    expect(exceededEvents())->toBe([]);
});

it('AC-20: dispatches RateLimitWouldBlock, then RateLimitExceeded, then StorageUnavailable, as evaluation met them', function (): void {
    Exceptions::fake();
    $order = [];
    Event::listen([RateLimitWouldBlock::class, RateLimitExceeded::class, StorageUnavailable::class], function (object $event) use (&$order): void {
        $order[] = $event::class;
    });
    Throttling::rule(['name' => 'Wide', 'priority' => 5, 'max_attempts' => 10]);
    Throttling::rule(['name' => 'Audit', 'priority' => 10, 'max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Tight', 'priority' => 20, 'max_attempts' => 1]);
    Throttling::route('api/x');
    app()->instance(LimiterStore::class, FailingStore::failingReleases(app(LimiterStore::class)));

    $this->get('api/x')->assertOk();
    $order = [];
    // Audit would block, Tight rejects, and releasing Wide's count fails.
    $this->get('api/x')->assertStatus(429);

    expect($order)->toBe([RateLimitWouldBlock::class, RateLimitExceeded::class, StorageUnavailable::class]);
});

it('AC-19: reports a RateLimitExceeded listener that throws, and keeps the 429', function (): void {
    Exceptions::fake();
    Event::listen(RateLimitExceeded::class, fn (): never => throw new RuntimeException('The listener failed.'));
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);

    expect(array_map(fn (Throwable $exception): string => $exception->getMessage(), Exceptions::reported()))->toBe(['The listener failed.']);
});
