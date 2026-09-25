<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Http\HeaderAuthenticate;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/*
| SPEC-010 AC-1 to AC-4: how the middleware plugs into an app (ADR-0004,
| ADR-0020 points 4 and 11). The clock stands at T0, the start of a window,
| and every request comes from the same IP.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The status of each request, one per entry of headers. Each request starts without
 * a user, as a real one does: the test app would otherwise keep its guards' users
 * from one request to the next.
 *
 * @param  list<array<string, string>>  $headers
 * @return list<int>
 */
function statusesWithFreshGuards(TestCase $test, string $uri, array $headers): array
{
    $statuses = [];

    foreach ($headers as $requestHeaders) {
        Auth::forgetGuards();
        $statuses[] = $test->get($uri, $requestHeaders)->status();
    }

    return $statuses;
}

it('AC-1: registers the dynamic.throttle alias, and attaches the middleware to no global middleware and no group', function (): void {
    $kernel = app(Kernel::class);
    $grouped = array_merge(...array_values($kernel->getMiddlewareGroups()));

    expect(app('router')->getMiddleware())->toHaveKey('dynamic.throttle', DynamicThrottleMiddleware::class)
        ->and($kernel->getGlobalMiddleware())->not->toContain(DynamicThrottleMiddleware::class)
        ->and($grouped)->not->toContain(DynamicThrottleMiddleware::class)
        ->and($grouped)->not->toContain('dynamic.throttle');
});

it('AC-2: limits no route in web or api with the default config, and limits a route that lists the middleware', function (): void {
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route('page', 'web');
    Throttling::route('api/x', 'api');
    Throttling::route('limited');

    $statuses = [];

    foreach (['page', 'api/x', 'limited'] as $uri) {
        $statuses[$uri] = [$this->get($uri)->status(), $this->get($uri)->status()];
    }

    expect($statuses)->toBe(['page' => [200, 200], 'api/x' => [200, 200], 'limited' => [200, 429]]);
});

it('AC-3: attaches the middleware to the groups in middleware.auto_attach, skips a group the app lacks, and leaves the others alone', function (): void {
    Throttling::bootWith(['dynamic-throttle.middleware.auto_attach' => ['api', 'missing']]);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route('page', 'web');
    Throttling::route('api/x', 'api');

    $web = [$this->get('page')->status(), $this->get('page')->status()];
    $api = [$this->get('api/x')->status(), $this->get('api/x')->status()];

    expect($web)->toBe([200, 200])
        ->and($api)->toBe([200, 429]);
});

it('AC-4: counts the user auth authenticates, in a group that lists the middleware before auth', function (): void {
    Auth::viaRequest('x-user', fn (Request $request): ?TestUser => $request->headers->has('X-User')
        ? new TestUser(['id' => (int) $request->headers->get('X-User')])
        : null);
    config()->set('auth.guards.header', ['driver' => 'x-user']);
    Throttling::rule(['scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x', ['dynamic.throttle', 'auth:header']);

    // Counted as guests, both users would share the one IP's count.
    expect(statusesWithFreshGuards($this, 'api/x', [['X-User' => '1'], ['X-User' => '2'], ['X-User' => '1']]))
        ->toBe([200, 200, 429]);
});

it('AC-4: runs an authentication middleware listed in middleware.after first, wherever the group lists it', function (string $after): void {
    app('router')->aliasMiddleware(HeaderAuthenticate::ALIAS, HeaderAuthenticate::class);
    Throttling::bootWith(['dynamic-throttle.middleware.after' => [$after]]);
    Throttling::rule(['scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x', ['dynamic.throttle', HeaderAuthenticate::ALIAS]);

    expect(statusesWithFreshGuards($this, 'api/x', [['X-User' => '1'], ['X-User' => '2'], ['X-User' => '1']]))
        ->toBe([200, 200, 429]);
})->with([
    'by alias' => [HeaderAuthenticate::ALIAS],
    'by alias, whose parameters are ignored' => [HeaderAuthenticate::ALIAS.':strict'],
    'by class' => [HeaderAuthenticate::class],
]);

it('AC-4: runs an authentication middleware Laravel cannot see after the middleware, until middleware.after lists it', function (): void {
    app('router')->aliasMiddleware(HeaderAuthenticate::ALIAS, HeaderAuthenticate::class);
    Throttling::rule(['scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x', ['dynamic.throttle', HeaderAuthenticate::ALIAS]);

    // Both users count as the same guest: the client IP (ADR-0020 point 4).
    expect(statusesWithFreshGuards($this, 'api/x', [['X-User' => '1'], ['X-User' => '2']]))
        ->toBe([200, 429]);
});
