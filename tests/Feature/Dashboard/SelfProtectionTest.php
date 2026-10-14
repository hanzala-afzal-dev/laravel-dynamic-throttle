<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;

/*
| SPEC-018 AC-9 to AC-11: no rule ever applies to the dashboard, by its routes'
| names, and by its path, which global middleware sees before routing
| (ADR-0004 point 5). A "*" rule allows 1 request a minute, so any route it
| applies to answers its 2nd request with a 429. The app runs locally, where
| the gates let everyone in.
*/

beforeEach(function (): void {
    Throttling::prepare();
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Dashboard::environment('local');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * dynamic.throttle as global middleware, which runs before routing.
 */
function throttleEverything(): void
{
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
}

/**
 * The statuses of $count requests to a path, from a client of their own, since the
 * rule counts each client across every path.
 *
 * @return list<int>
 */
function statuses(string $path, int $count): array
{
    $statuses = [];
    $ip = '198.51.100.'.(crc32($path) % 250 + 1);

    for ($request = 0; $request < $count; $request++) {
        $statuses[] = test()->withServerVariables(['REMOTE_ADDR' => $ip])->getJson($path)->status();
    }

    return $statuses;
}

it('AC-9: never limits the dashboard with dynamic.throttle on the web group, though the group\'s other routes are', function (): void {
    config()->set('dynamic-throttle.middleware.auto_attach', ['web']);
    Dashboard::boot();
    Route::middleware('web')->get('orders', fn (): string => 'ok');

    $dashboard = statuses('/dynamic-throttle/api/me', 10);
    $last = $this->getJson('/dynamic-throttle/api/me');

    expect($dashboard)->toBe(array_fill(0, 10, 200))
        // Laravel's own limit of the dashboard, not the rule's.
        ->and($last->headers->get('X-RateLimit-Limit'))->toBe('120')
        ->and(statuses('/orders', 2))->toBe([200, 429]);
});

it('AC-9: never limits the dashboard with dynamic.throttle as global middleware, which runs before routing', function (): void {
    Dashboard::boot();
    throttleEverything();
    Route::get('orders', fn (): string => 'ok');

    expect(statuses('/dynamic-throttle/api/me', 10))->toBe(array_fill(0, 10, 200))
        ->and(statuses('/orders', 2))->toBe([200, 429]);
});

it('AC-10: skips the path dashboard.path names, and limits the old path and a path that only starts the same', function (): void {
    Dashboard::boot(['path' => 'ops/throttle']);
    throttleEverything();
    Route::get('dynamic-throttle/x', fn (): string => 'ok');
    Route::get('dynamic-throttle-other', fn (): string => 'ok');
    Route::get('ops/throttle-other', fn (): string => 'ok');

    expect(statuses('/ops/throttle/api/me', 3))->toBe([200, 200, 200])
        ->and(statuses('/dynamic-throttle/x', 2))->toBe([200, 429])
        ->and(statuses('/dynamic-throttle-other', 2))->toBe([200, 429])
        ->and(statuses('/ops/throttle-other', 2))->toBe([200, 429]);
});

it('AC-10: limits the path again once the dashboard is turned off', function (): void {
    Dashboard::boot(['enabled' => false]);
    throttleEverything();
    Route::get('dynamic-throttle/x', fn (): string => 'ok');

    expect(statuses('/dynamic-throttle/x', 2))->toBe([200, 429]);
});

it('AC-11: says in explain that the dashboard goes through untouched, also under its path where no route matches', function (string $path): void {
    Dashboard::boot();
    throttleEverything();

    Artisan::call('dynamic-throttle:explain', ['method' => 'GET', 'path' => $path]);

    expect(Artisan::output())->toContain('Decision: untouched: the route is one of the package\'s own');
})->with(['/dynamic-throttle/api/me', '/dynamic-throttle/no-such-page']);
