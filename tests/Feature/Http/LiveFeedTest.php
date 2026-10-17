<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\RecordingTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route as Router;

/*
| SPEC-030 AC-1 to AC-3, AC-5 and AC-7: the entries the middleware gives the
| telemetry buffer for the live feed, after the response. The clock stands at T0+10.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
    $this->buffer = new RecordingTelemetryBuffer(app(TelemetryBuffer::class));
    app()->instance(TelemetryBuffer::class, $this->buffer);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Every entry the middleware gave the buffer, in order.
 *
 * @return list<FeedEntry>
 */
function feedEntries(RecordingTelemetryBuffer $buffer): array
{
    return array_merge(...$buffer->feeds);
}

it('AC-1: gives a blocked request\'s time, rule, method, route template, client IP and identity', function (): void {
    $rule = Throttling::rule(['max_attempts' => 1]);
    Router::middleware('dynamic.throttle')->post('api/orders/{order}', fn (): string => 'ok');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('api/orders/12')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->post('api/orders/12')->assertStatus(429);

    expect(feedEntries($this->buffer))->toEqual([
        new FeedEntry((FakeClock::T0 + 10) * 1_000, (string) $rule->id, TelemetryOutcome::Blocked, 'POST', 'api/orders/{order}', '203.0.113.7', null, Identities::hashed('ip:203.0.113.7')),
    ]);
});

it('AC-1: gives a dry-run rule\'s would-block, and nothing for an allowed request', function (): void {
    $rule = Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();

    expect(array_map(fn (FeedEntry $entry): array => [$entry->ruleId, $entry->outcome, $entry->route], feedEntries($this->buffer)))
        ->toBe([[(string) $rule->id, TelemetryOutcome::WouldBlock, 'api/x']])
        ->and($this->buffer->feeds[0])->toBe([]);
});

it('AC-2: keeps no token, header value, custom scope value, path value or query string', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => 'tenant-SECRET');
    Throttling::rule(['name' => 'Token', 'scope' => Scope::ApiToken, 'max_attempts' => 1, 'mode' => RuleMode::DryRun, 'priority' => 1]);
    Throttling::rule(['name' => 'Header', 'scope' => Scope::Header, 'scope_parameter' => 'X-Client', 'max_attempts' => 1, 'mode' => RuleMode::DryRun, 'priority' => 2]);
    Throttling::rule(['name' => 'Tenant', 'scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'max_attempts' => 1, 'mode' => RuleMode::DryRun, 'priority' => 3]);
    Router::middleware('dynamic.throttle')->get('api/reset/{token}', fn (): string => 'ok');
    $headers = ['Authorization' => 'Bearer token-SECRET', 'X-Client' => 'header-SECRET'];

    $this->withHeaders($headers)->get('api/reset/path-SECRET?signature=query-SECRET')->assertOk();
    $this->withHeaders($headers)->get('api/reset/path-SECRET?signature=query-SECRET')->assertOk();
    $kept = implode("\n", array_map(fn (FeedEntry $entry): string => $entry->toJson(), feedEntries($this->buffer)));

    expect(feedEntries($this->buffer))->toHaveCount(3)
        ->and(array_unique(array_map(fn (FeedEntry $entry): ?string => $entry->route, feedEntries($this->buffer))))->toBe(['api/reset/{token}'])
        ->and($kept)->not->toContain('SECRET');
});

it('AC-3: gives the user a user rule resolved, as Class:id', function (): void {
    Throttling::rule(['scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x');
    $user = new TestUser(['id' => 42]);

    $this->actingAs($user)->get('api/x')->assertOk();
    $this->actingAs($user)->get('api/x')->assertStatus(429);

    expect(array_map(fn (FeedEntry $entry): ?string => $entry->user, feedEntries($this->buffer)))->toBe([TestUser::class.':42']);
});

it('AC-3: gives the user the default guard already holds, without a user rule, and none without one', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);
    $this->actingAs(new TestUser(['id' => 7]))->get('api/x')->assertStatus(429);

    expect(array_map(fn (FeedEntry $entry): ?string => $entry->user, feedEntries($this->buffer)))->toBe([null, TestUser::class.':7']);
});

it('AC-3: never asks a guard for a user it has not loaded, and runs no query', function (): void {
    $lookups = 0;
    Auth::viaRequest('counting', function () use (&$lookups): TestUser {
        $lookups++;

        return new TestUser(['id' => 9]);
    });
    config()->set('auth.guards.counting', ['driver' => 'counting']);
    Auth::shouldUse('counting');
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $queries = 0;
    DB::listen(function () use (&$queries): void {
        $queries++;
    });

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);

    expect([$lookups, $queries, array_map(fn (FeedEntry $entry): ?string => $entry->user, feedEntries($this->buffer))])->toBe([0, 0, [null]]);
});

it('AC-5: gives no entry with the sample rate 0, the live feed off, or telemetry off', function (string $key, mixed $value, int $calls): void {
    config()->set($key, $value);
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertStatus(429);

    expect([count($this->buffer->recorded), feedEntries($this->buffer)])->toBe([$calls, []]);
})->with([
    'sample rate 0' => ['dynamic-throttle.telemetry.sample_rate', 0.0, 0],
    'live feed off' => ['dynamic-throttle.telemetry.live_feed', false, 2],
    'telemetry off' => ['dynamic-throttle.telemetry.enabled', false, 0],
]);

it('AC-5: gives no entry while the counter store\'s cooldown runs', function (): void {
    Exceptions::fake();
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 1, 'priority' => 2]);
    Throttling::route('api/login');
    app()->instance(LimiterStore::class, FailingStore::failingReleases(app(LimiterStore::class)));

    $this->get('api/login')->assertOk();
    // The 429's release fails, which starts the cooldown before terminate() runs.
    $this->get('api/login')->assertStatus(429);

    expect(feedEntries($this->buffer))->toBe([]);
});

it('AC-7: gives an IPv6 client\'s whole address, not its /64', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->get('api/x')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->get('api/x')->assertStatus(429);

    expect(array_map(fn (FeedEntry $entry): ?string => $entry->ip, feedEntries($this->buffer)))->toBe(['2001:db8::1']);
});

/*
| From the review.
*/

it('AC-2: keeps a method longer than 16 characters, which a client can send ahead of routing, as OTHER', function (): void {
    app(HttpKernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);

    $this->post('api/x', ['_method' => 'PROPFIND']);
    $this->post('api/x', ['_method' => 'PROPFIND']);
    $this->post('api/x', ['_method' => str_repeat('A', 10_000)]);
    $this->post('api/x', ['_method' => 'BASELINECONTROLS']);

    expect(array_map(fn (FeedEntry $entry): string => $entry->method, feedEntries($this->buffer)))->toBe(['PROPFIND', 'OTHER', 'BASELINECONTROLS']);
});

it('AC-3: still records the request, without a user, when the held user\'s id cannot be read', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $user = new GenericUser(['id' => new class implements Stringable
    {
        public function __toString(): string
        {
            throw new RuntimeException('The id cannot be read.');
        }
    }]);

    $this->actingAs($user)->get('api/x')->assertOk();
    $this->actingAs($user)->get('api/x')->assertStatus(429);

    expect([count($this->buffer->recorded), array_map(fn (FeedEntry $entry): ?string => $entry->user, feedEntries($this->buffer))])->toBe([2, [null]]);
});
