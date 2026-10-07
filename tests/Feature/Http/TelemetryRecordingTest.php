<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\RecordingTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-015 AC-1, AC-2 and AC-7 to AC-9: the middleware collects each request's
| numbers in handle() and writes them in terminate(). SQLite and the array
| cache store; the clock stands at T0+10, in minute T0/60.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The app's buffer, wrapped so the test sees every call.
 */
function recordingTelemetry(): RecordingTelemetryBuffer
{
    $buffer = new RecordingTelemetryBuffer(app(TelemetryBuffer::class));
    app()->instance(TelemetryBuffer::class, $buffer);

    return $buffer;
}

it('AC-1: records each rule\'s hits, blocks and would-blocks for the minute', function (): void {
    Throttling::rule(['name' => 'B', 'priority' => 10, 'max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'A', 'priority' => 20, 'max_attempts' => 2]);
    Throttling::rule(['name' => 'C', 'priority' => 30]);
    Throttling::route('api/x');

    $statuses = array_map(fn (): int => $this->get('api/x')->status(), range(1, 3));

    expect($statuses)->toBe([200, 200, 429])
        ->and(Telemetry::counts(Telemetry::minute(FakeClock::T0 + 10), ['1', '2', '3']))->toEqual([
            '1' => [3, 0, 2],
            '2' => [3, 1, 0],
            '3' => [2, 0, 0],
        ]);
});

it('AC-1: records nothing for a rule the client is exempt from, a skipped rule, or a rule over the cap', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    $exempt = Throttling::rule(['name' => 'Exempt', 'priority' => 10]);
    Throttling::rule(['name' => 'Partners', 'priority' => 20, 'scope' => Scope::Header, 'scope_parameter' => 'X-Partner', 'on_missing_identity' => MissingIdentity::Skip]);
    Throttling::rule(['name' => 'Counted', 'priority' => 30]);
    Throttling::rule(['name' => 'Capped', 'priority' => 40]);
    Exemption::forIp('127.0.0.1')->forRule($exempt)->save();
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    expect(Telemetry::counts(Telemetry::minute(FakeClock::T0 + 10), ['1', '2', '3', '4']))->toBe(['3' => [1, 0, 0]]);
});

it('AC-1: records nothing for a fail-closed rule whose identity fails, or the rules after it', function (): void {
    DynamicThrottle::scope('broken', fn (): never => throw new RuntimeException('The plans service is down.'));
    Throttling::rule(['name' => 'Plans', 'priority' => 10, 'scope' => Scope::Custom, 'scope_parameter' => 'broken', 'on_failure' => FailurePolicy::Closed]);
    Throttling::rule(['name' => 'After', 'priority' => 20]);
    Throttling::route('api/x');

    $this->get('api/x')->assertStatus(503);

    expect(Telemetry::counts(Telemetry::minute(FakeClock::T0 + 10), ['1', '2']))->toBe([]);
});

it('AC-1: records a request a later rule rejects as a hit on the rules that counted it before', function (): void {
    Throttling::rule(['name' => 'Wide', 'priority' => 10, 'max_attempts' => 10]);
    Throttling::rule(['name' => 'Tight', 'priority' => 20, 'max_attempts' => 1]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);

    // The second request's count on Wide was released (ADR-0021), but Wide checked it.
    expect(Telemetry::counts(Telemetry::minute(FakeClock::T0 + 10), ['1', '2']))->toEqual(['1' => [2, 0, 0], '2' => [2, 1, 0]]);
});

it('AC-2: writes nothing in handle(), writes once in terminate(), and once only when the middleware terminates twice', function (): void {
    $buffer = recordingTelemetry();
    Throttling::rule();
    Throttling::route('api/x');
    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/x');

    $response = $kernel->handle($request);
    $afterHandle = $buffer->recorded;
    $kernel->terminate($request, $response);
    // An app that also lists the middleware globally terminates it twice.
    app(DynamicThrottleMiddleware::class)->terminate($request);

    expect([$response->getStatusCode(), $afterHandle])->toBe([200, []])
        ->and($buffer->recorded)->toBe([[
            Telemetry::minute(FakeClock::T0 + 10),
            1,
            [['1', Identities::hashed('ip:127.0.0.1'), 'counted']],
        ]]);
});

it('AC-2: records the minute of terminate(), with one record per rule, in evaluation order', function (): void {
    $buffer = recordingTelemetry();
    Throttling::rule(['name' => 'Second', 'priority' => 20, 'mode' => RuleMode::DryRun, 'max_attempts' => 1]);
    Throttling::rule(['name' => 'First', 'priority' => 10]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 75));

    $this->get('api/x')->assertOk();

    $client = Identities::hashed('ip:127.0.0.1');
    expect($buffer->recorded)->toBe([
        [Telemetry::minute(FakeClock::T0 + 10), 1, [['2', $client, 'counted'], ['1', $client, 'counted']]],
        [Telemetry::minute(FakeClock::T0 + 75), 1, [['2', $client, 'counted'], ['1', $client, 'would_block']]],
    ]);
});

it('AC-2: records both runs of a middleware the app also lists globally, in one write', function (): void {
    $buffer = recordingTelemetry();
    Throttling::rule(['mode' => RuleMode::DryRun, 'max_attempts' => 1]);
    Throttling::route('api/x');
    app(HttpKernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);

    $this->get('api/x')->assertOk();

    // The global run counts the request first, and the route's run counts it again, which would block.
    $client = Identities::hashed('ip:127.0.0.1');
    expect(array_map(fn (array $call): array => $call[2], $buffer->recorded))->toBe([[['1', $client, 'counted'], ['1', $client, 'would_block']]]);
});

it('AC-7: records nothing with the sample rate 0, or with telemetry off', function (string $key, mixed $value): void {
    $buffer = recordingTelemetry();
    config()->set($key, $value);
    Throttling::rule();
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    expect($buffer->recorded)->toBe([]);
})->with([
    'sample rate 0' => ['dynamic-throttle.telemetry.sample_rate', 0.0],
    'telemetry off' => ['dynamic-throttle.telemetry.enabled', false],
]);

it('AC-7: records every request with weight 1 when the sample rate is not one from 0 to 1', function (mixed $rate): void {
    $buffer = recordingTelemetry();
    config()->set('dynamic-throttle.telemetry.sample_rate', $rate);
    Throttling::rule();
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();

    expect(array_map(fn (array $call): int => $call[1], $buffer->recorded))->toBe([1, 1]);
})->with([
    'above 1' => [7],
    'below 0' => [-0.5],
    'not a number' => ['often'],
]);

it('AC-8: reports a buffer that fails once per cooldown, keeps the responses and the events, and starts no cooldown', function (): void {
    Exceptions::fake();
    Event::fake([RateLimitWouldBlock::class]);
    $buffer = recordingTelemetry();
    $buffer->recordFailure = StorageUnavailableException::because('The telemetry store is down.');
    Throttling::rule(['mode' => RuleMode::DryRun, 'max_attempts' => 1]);
    Throttling::route('api/x');

    $statuses = array_map(fn (): int => $this->get('api/x')->status(), range(1, 3));

    expect($statuses)->toBe([200, 200, 200])
        ->and(count($buffer->recorded))->toBe(3)
        ->and(array_map(fn (Throwable $exception): string => $exception->getMessage(), Exceptions::reported()))->toBe(['The telemetry store is down.'])
        ->and(Event::dispatched(RateLimitWouldBlock::class))->toHaveCount(2)
        ->and(app(Cooldown::class)->secondsLeft())->toBe(0);
});

it('AC-8: writes nothing while the counter store\'s cooldown runs, such as after a failed release', function (): void {
    Exceptions::fake();
    $buffer = recordingTelemetry();
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 1, 'priority' => 2]);
    Throttling::route('api/login');
    app()->instance(LimiterStore::class, FailingStore::failingReleases(app(LimiterStore::class)));

    $this->get('api/login')->assertOk();
    // The 429's release fails, which starts the cooldown before terminate() runs.
    $this->get('api/login')->assertStatus(429);

    expect(array_map(fn (array $call): int => count($call[2]), $buffer->recorded))->toBe([2]);
});

it('AC-9: records nothing for a request let through untouched', function (Closure $untouched, string $path, string $pattern): void {
    $buffer = recordingTelemetry();
    Throttling::rule(['pattern' => $pattern]);
    Throttling::route($path);
    $untouched();

    $this->get($path)->assertOk();

    expect($buffer->recorded)->toBe([]);
})->with([
    'the kill switch' => [fn () => config()->set('dynamic-throttle.enabled', false), 'api/x', '*'],
    'except' => [fn (): null => null, 'up', '*'],
    'bypass' => [fn (): Setting => Settings::bypass(), 'api/x', '*'],
    'no rule' => [fn (): null => null, 'api/x', 'admin/*'],
]);

it('records into fake()\'s in-memory store, like the counters', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    DynamicThrottle::fake();

    $this->get('api/x')->assertOk();

    expect(Telemetry::counts(Telemetry::minute(FakeClock::T0 + 10), ['1']))->toBe(['1' => [1, 0, 0]]);
});
