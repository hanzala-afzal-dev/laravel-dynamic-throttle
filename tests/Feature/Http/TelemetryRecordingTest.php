<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\Telemetry\Sampler;
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
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Http\TrustProxyLater;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\RecordingTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Random\Engine;
use Random\Randomizer;

/*
| SPEC-015 AC-1, AC-2 and AC-7 to AC-9: the middleware collects each request's
| numbers in handle() and writes them in terminate(). SPEC-016 AC-14 and AC-15
| add the guests, the skipped and ignored rules, and the request's client.
| SQLite and the array cache store; the clock stands at T0+10, in minute T0/60.
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

it('AC-1: records no hit for a rule the client is exempt from, a skipped rule, or a rule over the cap, and SPEC-016 counts the last two apart', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    $exempt = Throttling::rule(['name' => 'Exempt', 'priority' => 10]);
    Throttling::rule(['name' => 'Partners', 'priority' => 20, 'scope' => Scope::Header, 'scope_parameter' => 'X-Partner', 'on_missing_identity' => MissingIdentity::Skip]);
    Throttling::rule(['name' => 'Counted', 'priority' => 30]);
    Throttling::rule(['name' => 'Capped', 'priority' => 40]);
    Exemption::forIp('127.0.0.1')->forRule($exempt)->save();
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    // As [hits, blocks, would_blocks, guests, skipped, ignored]: SPEC-015 recorded only rule 3.
    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2', '3', '4']))->toBe([
        '2' => [0, 0, 0, 0, 1, 0],
        '3' => [1, 0, 0, 0, 0, 0],
        '4' => [0, 0, 0, 0, 0, 1],
    ]);
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

/**
 * A sampler that picks every request, at the given rate, so each stands for 1/rate.
 */
function samplingEveryRequest(float $rate): void
{
    $first = new class implements Engine
    {
        public function generate(): string
        {
            return str_repeat("\0", 8);
        }
    };
    app()->bind(Sampler::class, fn (): Sampler => new Sampler($rate, new Randomizer($first)));
}

it('SPEC-016 AC-14: records a user rule\'s guests, a skip rule\'s skipped requests, and the rules ignored over the cap', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    Throttling::rule(['name' => 'Users', 'priority' => 10, 'scope' => Scope::User]);
    Throttling::rule(['name' => 'Members', 'priority' => 20, 'scope' => Scope::User, 'on_missing_identity' => MissingIdentity::Skip, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Addresses', 'priority' => 30]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->actingAs(new TestUser(['id' => 42]))->get('api/x')->assertOk();

    // As [hits, blocks, would_blocks, guests, skipped, ignored].
    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2', '3']))->toBe([
        '1' => [2, 0, 0, 1, 0, 0],
        '2' => [1, 0, 0, 0, 1, 0],
        '3' => [0, 0, 0, 0, 0, 2],
    ]);
});

it('SPEC-016 AC-14: weighs the new numbers by the sample, as hits are', function (): void {
    samplingEveryRequest(0.25);
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    Throttling::rule(['name' => 'Users', 'priority' => 10, 'scope' => Scope::User]);
    // In dry run, as above: an enforcing rule would be ignored over the cap before its identity is resolved (ADR-0025).
    Throttling::rule(['name' => 'Members', 'priority' => 20, 'scope' => Scope::User, 'on_missing_identity' => MissingIdentity::Skip, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Addresses', 'priority' => 30]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2', '3']))->toBe([
        '1' => [4, 0, 0, 4, 0, 0],
        '2' => [0, 0, 0, 0, 4, 0],
        '3' => [0, 0, 0, 0, 0, 4],
    ]);
});

it('SPEC-016 AC-14: counts a user rule\'s guest when it blocks or would block one too, and never a user as a guest', function (): void {
    Throttling::rule(['name' => 'Trial', 'priority' => 10, 'scope' => Scope::User, 'mode' => RuleMode::DryRun, 'max_attempts' => 1]);
    Throttling::rule(['name' => 'Users', 'priority' => 20, 'scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);
    $this->actingAs(new TestUser(['id' => 42]))->get('api/x')->assertOk();

    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2']))->toBe([
        '1' => [3, 0, 1, 2, 0, 0],
        '2' => [3, 1, 0, 2, 0, 0],
    ]);
});

it('SPEC-016 AC-14: records a header rule that skips a request without the header as skipped, and no guest', function (): void {
    Throttling::rule(['name' => 'Partners', 'scope' => Scope::Header, 'scope_parameter' => 'X-Partner', 'on_missing_identity' => MissingIdentity::Skip]);
    Throttling::rule(['name' => 'Tokens', 'priority' => 20, 'scope' => Scope::ApiToken]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    // The token rule counts the request without a token under its IP: a hit, and not a guest, which only user rules have.
    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2']))->toBe([
        '1' => [0, 0, 0, 0, 1, 0],
        '2' => [1, 0, 0, 0, 0, 0],
    ]);
});

it('SPEC-016 AC-14: counts no guest for a user rule whose scope fails, which records nothing', function (): void {
    Exceptions::fake();
    config()->set('dynamic-throttle.scopes.user.guards', ['broken']);
    config()->set('auth.guards.broken', ['driver' => 'missing-driver']);
    Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Throttling::rule(['name' => 'Addresses', 'priority' => 20]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();

    expect(Telemetry::all(Telemetry::minute(FakeClock::T0 + 10), ['1', '2']))->toBe(['2' => [1, 0, 0, 0, 0, 0]]);
});

it('SPEC-016 AC-15: gives the buffer the request\'s client, by the identity the ip scope gives it, after the response', function (string $ip, string $identity): void {
    $buffer = recordingTelemetry();
    Throttling::rule(['scope' => Scope::Global]);
    Throttling::route('api/x');

    $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('api/x')->assertOk();

    expect($buffer->clients)->toBe([Identities::hashed($identity)]);
})->with([
    'an IPv4 client' => ['203.0.113.7', 'ip:203.0.113.7'],
    // Grouped by scopes.ip.ipv6_prefix, 64 by default, as the ip scope counts it.
    'an IPv6 client' => ['2001:db8::1', 'ip:2001:db8::/64'],
    'another client of the same /64' => ['2001:db8::2', 'ip:2001:db8::/64'],
]);

it('SPEC-016 AC-15: groups an IPv6 client by the configured prefix', function (): void {
    $buffer = recordingTelemetry();
    config()->set('dynamic-throttle.scopes.ip.ipv6_prefix', 48);
    Throttling::rule(['scope' => Scope::Global]);
    Throttling::route('api/x');

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8:1:2::1'])->get('api/x')->assertOk();

    expect($buffer->clients)->toBe([Identities::hashed('ip:2001:db8:1::/48')]);
});

it('SPEC-016 AC-15: gives no client when the client IP cannot be read, and still records the numbers', function (): void {
    Exceptions::fake();
    $buffer = recordingTelemetry();
    Throttling::rule(['scope' => Scope::Global]);
    Throttling::route('api/x');
    // The app trusts the proxy for both headers, and the two disagree.
    TrustProxies::at('*');
    TrustProxies::withHeaders(Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR);

    try {
        $this->get('api/x', ['Forwarded' => 'for=198.51.100.1', 'X-Forwarded-For' => '198.51.100.2'])->assertOk();
    } finally {
        // Trusted proxies are static on Symfony's Request: back to its defaults.
        TrustProxies::flushState();
        Request::setTrustedProxies([], -1);
    }

    expect($buffer->clients)->toBe([null])
        ->and(array_map(fn (array $call): array => $call[2], $buffer->recorded))->toBe([[['1', Identities::hashed('global'), 'counted']]]);
});

it('SPEC-016 AC-15: gives the client the rules counted, though the app trusts its proxy only after dynamic.throttle', function (): void {
    $buffer = recordingTelemetry();
    // An ip rule, which counts the client as handle() reads it.
    Throttling::rule();
    Throttling::route('api/x', ['dynamic.throttle', TrustProxyLater::class]);

    try {
        $this->withServerVariables(['REMOTE_ADDR' => TrustProxyLater::PROXY])->get('api/x', ['X-Forwarded-For' => '203.0.113.7'])->assertOk();
    } finally {
        // Trusted proxies are static on Symfony's Request: back to its defaults.
        Request::setTrustedProxies([], -1);
    }

    // The rule counted the proxy, so the traffic names the proxy: that is what the dominant-ip check must see.
    $proxy = Identities::hashed('ip:'.TrustProxyLater::PROXY);

    expect(array_map(fn (array $call): array => $call[2], $buffer->recorded))->toBe([[['1', $proxy, 'counted']]])
        ->and($buffer->clients)->toBe([$proxy]);
});
