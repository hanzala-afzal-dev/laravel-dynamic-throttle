<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Route;

/*
| SPEC-010 AC-15 and AC-16: only the 429 leaves the middleware (ADR-0020 point
| 1). Until SPEC-012, a failure lets the request through, and its error is
| reported once (decision 2).
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
    // Trusted proxies are static on Symfony's Request: back to its defaults.
    TrustProxies::flushState();
    Request::setTrustedProxies([], -1);
});

/**
 * Every reported exception, as its class and message, in the order reported.
 *
 * @return list<array{class-string<Throwable>, string}>
 */
function reportedErrors(): array
{
    return array_map(fn (Throwable $exception): array => [$exception::class, $exception->getMessage()], Exceptions::reported());
}

it('AC-15: lets the request through without headers, and reports once, when the counter store throws', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    app()->instance(LimiterStore::class, FailingStore::down());
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(reportedErrors())->toBe([[StorageUnavailableException::class, FailingStore::DOWN]]);
});

it('AC-15: lets the request through without headers, and reports once, for a config mistake', function (string $key, string $value, string $message): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    config()->set("dynamic-throttle.{$key}", $value);
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(reportedErrors())->toBe([[InvalidArgumentException::class, $message]]);
})->with([
    'an invalid driver' => ['driver', 'nope', 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.'],
    'an undefined store' => ['store', 'missing', 'Cache store [missing] is not defined.'],
    'an invalid prefix' => ['prefix', 'bad prefix!', 'The key prefix must be at most 32 characters from A-Z, a-z, 0-9, ":", "_", "." and "-".'],
]);

it('AC-15: lets the request through without headers, and reports once, when the manifest is corrupt', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    Cache::forever(app(KeyBuilder::class)->manifest(), '{"format": 2');
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(array_map(fn (Throwable $exception): string => $exception::class, Exceptions::reported()))->toBe([InvalidManifest::class]);
});

it('AC-15: reports a failing custom scope once, for two rules and an exemption, and lets the request through', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'max_attempts' => 1]);
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'max_attempts' => 1]);
    Exemption::forCustom('tenant', 'acme')->save();
    Throttling::route('api/x');
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(reportedErrors())->toBe([[IdentityUnavailableException::class, 'The identity for custom:tenant could not be resolved: The tenant lookup failed.']]);
});

it('AC-15: reports a failed exemption check, and counts the request under the rule as usual (SPEC-009)', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['max_attempts' => 5]);
    Exemption::forCustom('tenant', 'acme')->save();
    Throttling::route('api/x');
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4'])
        ->and(reportedErrors())->toBe([[IdentityUnavailableException::class, 'The identity for custom:tenant could not be resolved: The tenant lookup failed.']]);
});

it('AC-15: keeps the headers of the enforcing rule that counted a request another rule failed on', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['max_attempts' => 5]);
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'max_attempts' => 1]);
    Throttling::route('api/x');
    Exceptions::fake();

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4'])
        ->and(reportedErrors())->toBe([[IdentityUnavailableException::class, 'The identity for custom:tenant could not be resolved: The tenant lookup failed.']]);
});

it('AC-15: reports a client IP it cannot read once, however many rules count by IP', function (): void {
    // The app trusts the proxy for both headers, and the two disagree.
    TrustProxies::at('*');
    TrustProxies::withHeaders(Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR);
    Throttling::rule(['max_attempts' => 1]);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    Exceptions::fake();

    $response = $this->get('api/x', ['Forwarded' => 'for=198.51.100.1', 'X-Forwarded-For' => '198.51.100.2']);

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(array_map(fn (Throwable $exception): string => $exception::class, Exceptions::reported()))->toBe([IdentityUnavailableException::class]);
});

it('AC-15: keeps the 429 when a release fails, and reports that failure once (ADR-0021)', function (): void {
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 1, 'priority' => 2]);
    Throttling::route('api/login');
    app()->instance(LimiterStore::class, FailingStore::failingReleases(app(LimiterStore::class)));
    Exceptions::fake();

    $statuses = [$this->get('api/login')->status(), $this->get('api/login')->status()];

    expect($statuses)->toBe([200, 429])
        ->and(reportedErrors())->toBe([[StorageUnavailableException::class, FailingStore::RELEASE_FAILED]]);
});

it('AC-16: leaves an exception the route throws to the app\'s handler, reports nothing of its own, and still counts the request', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Route::middleware('dynamic.throttle')->get('api/x', fn () => throw new RuntimeException('The route failed.'));
    Exceptions::fake();

    $statuses = [$this->get('api/x')->status(), $this->get('api/x')->status()];

    // The first request counted, so the second is limited before the route runs.
    expect($statuses)->toBe([500, 429])
        ->and(reportedErrors())->toBe([[RuntimeException::class, 'The route failed.']]);
});
