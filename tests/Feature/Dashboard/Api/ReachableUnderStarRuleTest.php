<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
| The roadmap's Phase 4 exit: the dashboard stays reachable under a "*" rule of 1
| request a minute, on every route of its group, with dynamic.throttle on the web
| group and as global middleware (SPEC-018 AC-9 showed it for api/me), the page
| shell and the assets included (SPEC-020 AC-6). Each route gets two requests from
| the manager, with no body, so changes are refused or do little; neither may be
| the rule's 429.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    Throttling::rule(['name' => 'Everything', 'pattern' => '*', 'max_attempts' => 1]);
    $this->rule = Throttling::rule(['name' => 'Orders', 'pattern' => 'orders']);
    $this->exemption = Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '10.0.0.1']);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('keeps every dashboard route reachable under a "*" rule', function (string $attached): void {
    if ($attached === 'web') {
        config()->set('dynamic-throttle.middleware.auto_attach', ['web']);
        Dashboard::boot();
        Route::middleware('web')->get('orders', fn (): string => 'ok');
    } else {
        Dashboard::boot();
        app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
        Route::get('orders', fn (): string => 'ok');
    }

    Dashboard::fixtureBuild();

    $routes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'dynamic-throttle.'));
    $seen = [];

    foreach ($routes as $route) {
        $method = array_values(array_diff($route->methods(), ['HEAD']))[0];
        $path = '/'.str_replace(
            ['{rule}', '{exemption}', '{view?}', '{file}'],
            [(string) $this->rule->id, (string) $this->exemption->id, 'rules', 'main-abc123.js'],
            $route->uri(),
        );

        DashboardApi::sendTo($this, $method, $path, [], DashboardApi::manager());
        $second = DashboardApi::sendTo($this, $method, $path, [], DashboardApi::manager());
        $seen[] = [(string) $route->getName(), $second->status() === 429 || $second->headers->get('X-RateLimit-Limit') === '1'];
    }

    $orders = [$this->getJson('/orders')->status(), $this->getJson('/orders')->status()];

    expect(array_column($seen, 0))->toContain('dynamic-throttle.page', 'dynamic-throttle.asset')
        ->and(count($seen))->toBeGreaterThan(17)
        ->and(array_filter($seen, fn (array $route): bool => $route[1]))->toBe([])
        ->and($orders)->toBe([200, 429]);
})->with(['web', 'global']);
