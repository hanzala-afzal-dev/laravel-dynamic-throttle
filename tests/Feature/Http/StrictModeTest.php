<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\StorageUnavailable;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-012 AC-9: strict mode (ADR-0020 point 1). It rethrows programming
| mistakes, and never what code cannot prevent: storage, the manifest, or a
| client IP that cannot be read (decision 4).
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Exceptions::fake();
});

afterEach(function (): void {
    ManifestRepository::flushState();
    // Trusted proxies are static on Symfony's Request: back to its defaults.
    TrustProxies::flushState();
    Request::setTrustedProxies([], -1);
});

it('AC-9: is off by default, so a config mistake lets the request through and is reported', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    config()->set('dynamic-throttle.driver', 'nope');

    $response = $this->get('api/x');

    expect(DynamicThrottle::isStrict())->toBeFalse()
        ->and($response->status())->toBe(200)
        ->and(Exceptions::reported())->toHaveCount(1);
});

it('AC-9: rethrows a config mistake in strict mode', function (): void {
    DynamicThrottle::strict();
    Throttling::rule();
    Throttling::route('api/x');
    config()->set('dynamic-throttle.driver', 'nope');
    $this->withoutExceptionHandling();

    expect(DynamicThrottle::isStrict())->toBeTrue()
        ->and(fn () => $this->get('api/x'))->toThrow(InvalidArgumentException::class, 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.');
});

it('AC-9: rethrows a throwing resolver in strict mode, with the app\'s exception as its previous one', function (): void {
    DynamicThrottle::strict();
    $cause = new RuntimeException('The tenant lookup failed.');
    DynamicThrottle::scope('tenant', fn (): string => throw $cause);
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant']);
    Throttling::route('api/x');
    $this->withoutExceptionHandling();
    $thrown = null;

    try {
        $this->get('api/x');
    } catch (IdentityUnavailableException $exception) {
        $thrown = $exception;
    }

    expect($thrown?->getMessage())->toBe('The identity for custom:tenant could not be resolved: The tenant lookup failed.')
        ->and($thrown?->getPrevious())->toBe($cause);
});

it('AC-9: rethrows each kind of programming mistake in strict mode', function (Closure $mistake, string $class): void {
    DynamicThrottle::strict();
    Throttling::route('api/x');
    $mistake();
    $this->withoutExceptionHandling();

    expect(fn () => $this->get('api/x'))->toThrow($class);
})->with([
    'an undefined store' => [function (): void {
        Throttling::rule();
        config()->set('dynamic-throttle.store', 'missing');
    }, InvalidArgumentException::class],
    'an invalid prefix' => [function (): void {
        Throttling::rule();
        config()->set('dynamic-throttle.prefix', 'bad prefix!');
    }, InvalidArgumentException::class],
    'a custom scope that is not registered' => [function (): void {
        Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'nope']);
    }, IdentityUnavailableException::class],
    'a custom scope that returns an unusable value' => [function (): void {
        DynamicThrottle::scope('plan', fn (): array => ['gold']);
        Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'plan']);
    }, IdentityUnavailableException::class],
    'a guard that throws' => [function (): void {
        Auth::viaRequest('broken', fn (): never => throw new RuntimeException('The guard failed.'));
        config()->set('auth.guards.broken', ['driver' => 'broken']);
        config()->set('dynamic-throttle.scopes.user.guards', ['broken']);
        Throttling::rule(['scope' => Scope::User]);
    }, IdentityUnavailableException::class],
    'a user id of an unusable type' => [function (): void {
        test()->actingAs(new TestUser(['id' => [1, 2]]));
        Throttling::rule(['scope' => Scope::User]);
    }, IdentityUnavailableException::class],
    'an exemption check whose resolver throws' => [function (): void {
        DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
        Exemption::forCustom('tenant', 'acme')->save();
        Throttling::rule();
    }, IdentityUnavailableException::class],
]);

it('AC-9: still reports a store that failed, and dispatches StorageUnavailable, when it rethrows a mistake of the same request', function (): void {
    Event::fake([StorageUnavailable::class]);
    DynamicThrottle::strict();
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['max_attempts' => 5, 'priority' => 1]);
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'priority' => 2]);
    Throttling::route('api/x');
    app()->instance(LimiterStore::class, FailingStore::down());

    $response = $this->get('api/x');

    // The app's handler renders and reports the mistake, and the outage is not lost with it.
    expect($response->status())->toBe(500)
        ->and(array_map(fn (Throwable $exception): string => $exception::class, Exceptions::reported()))
        ->toBe([StorageUnavailableException::class, IdentityUnavailableException::class])
        ->and(Event::dispatched(StorageUnavailable::class))->toHaveCount(1);
});

it('AC-9: turns strict mode off again', function (): void {
    DynamicThrottle::strict();
    DynamicThrottle::strict(false);
    Throttling::rule();
    Throttling::route('api/x');
    config()->set('dynamic-throttle.driver', 'nope');

    expect(DynamicThrottle::isStrict())->toBeFalse()
        ->and($this->get('api/x')->status())->toBe(200);
});

it('AC-9: still applies the policy to a failing store in strict mode', function (): void {
    DynamicThrottle::strict();
    Throttling::rule(['pattern' => 'api/open']);
    Throttling::rule(['pattern' => 'api/closed', 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/open');
    Throttling::route('api/closed');
    app()->instance(LimiterStore::class, FailingStore::down());

    expect([$this->get('api/open')->status(), $this->get('api/closed')->status()])->toBe([200, 503]);
});

it('AC-9: still applies the policy to a corrupt manifest in strict mode', function (): void {
    DynamicThrottle::strict();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    Cache::forever(app(KeyBuilder::class)->manifest(), '{"format": 2');

    expect($this->get('api/x')->status())->toBe(200);
});

it('AC-9: still applies the policy to a client IP that cannot be read in strict mode', function (): void {
    DynamicThrottle::strict();
    // The app trusts the proxy for both headers, and the two disagree.
    TrustProxies::at('*');
    TrustProxies::withHeaders(Request::HEADER_FORWARDED | Request::HEADER_X_FORWARDED_FOR);
    Throttling::rule();
    Throttling::route('api/x');

    $response = $this->withHeaders(['Forwarded' => 'for=198.51.100.1', 'X-Forwarded-For' => '198.51.100.2'])->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Exceptions::reported())->toHaveCount(1)
        ->and(Exceptions::reported()[0])->toBeInstanceOf(IdentityUnavailableException::class);
});

it('SPEC-027: rethrows the resolver error of a rule after the one that rejects, since every identity is resolved before the store answers', function (): void {
    DynamicThrottle::strict();
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 1, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'priority' => 2]);
    Throttling::route('api/login');
    Throttling::route('api/other');
    $this->get('api/other');
    $this->withoutExceptionHandling();

    // Outside strict mode the request gets the 429, and the error is reported (SPEC-012 AC-4).
    expect(fn () => $this->get('api/login'))
        ->toThrow(IdentityUnavailableException::class, 'The identity for custom:tenant could not be resolved: The tenant lookup failed.');
});
