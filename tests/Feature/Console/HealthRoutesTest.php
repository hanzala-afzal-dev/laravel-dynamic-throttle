<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| SPEC-016 AC-8 to AC-11: the checks of the routes, which read them as explain
| does (SPEC-014, decision 10). SQLite and a store kept in memory; the clock
| stands at 2027-01-15 11:00:30 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Twelve routes that run Laravel's throttle and dynamic.throttle, api/r1 to api/r12.
 */
function healthTwelveLimitedRoutes(): void
{
    foreach (range(1, 12) as $n) {
        Route::middleware(['throttle:60,1', 'dynamic.throttle'])->get("api/r{$n}", fn (): string => 'ok');
    }
}

it('AC-8: warns when dynamic.throttle runs on no route and is not global middleware', function (): void {
    Health::passing(attached: false);
    Route::get('plain', fn (): string => 'ok');

    [$status, $message] = Health::check('attached');

    expect($status)->toBe('warning')
        ->and($message)->toContain('middleware.auto_attach');
});

it('AC-8: is ok when a route runs dynamic.throttle, through its group too, or the app runs it as global middleware', function (Closure $attach): void {
    Health::passing(attached: false);
    $attach();

    expect(Health::status('attached'))->toBe('ok');
})->with([
    'a route' => [fn (): Illuminate\Routing\Route => Throttling::route('api/x')],
    'a group in auto_attach' => [function (): void {
        Throttling::bootWith(['dynamic-throttle.middleware.auto_attach' => ['api']]);
        Route::middleware('api')->get('api/x', fn (): string => 'ok');
    }],
    'global middleware' => [fn (): Kernel => app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class)],
]);

it('AC-9: warns about an installed package\'s path that except does not cover, naming the package, its path and the patterns to add', function (array $config, string $package, string $path, array $patterns): void {
    Health::passing();
    config()->set($config);

    [$status, $message] = Health::check('except');

    expect($status)->toBe('warning')
        ->and($message)->toContain($package)->toContain($path);

    foreach ($patterns as $pattern) {
        expect($message)->toContain("\"{$pattern}\"");
    }
})->with([
    'Horizon elsewhere' => [['horizon.path' => 'admin/horizon'], 'Horizon', 'admin/horizon', ['admin/horizon', 'admin/horizon/*']],
    'Telescope elsewhere' => [['telescope.path' => 'debug/telescope'], 'Telescope', 'debug/telescope', ['debug/telescope', 'debug/telescope/*']],
    'Pulse elsewhere' => [['pulse.path' => 'ops/pulse'], 'Pulse', 'ops/pulse', ['ops/pulse', 'ops/pulse/*']],
    'Nova elsewhere' => [['nova.path' => '/admin'], 'Nova', 'admin', ['admin', 'admin/*']],
    'Nova\'s API without its pattern' => [['nova.path' => '/nova', 'dynamic-throttle.except' => ['nova', 'nova/*']], 'Nova', 'nova-api', ['nova-api/*']],
    'Sanctum elsewhere' => [['sanctum.prefix' => 'auth'], 'Sanctum', 'auth/csrf-cookie', ['auth/csrf-cookie']],
]);

it('AC-9: is ok with the packages at their default paths and the default except, or with a pattern that covers a path', function (array $config): void {
    Health::passing();
    config()->set($config);

    expect(Health::status('except'))->toBe('ok');
})->with([
    'the defaults' => [['horizon.path' => 'horizon', 'telescope.path' => 'telescope', 'pulse.path' => 'pulse', 'nova.path' => '/nova', 'sanctum.prefix' => 'sanctum']],
    'a wider pattern' => [['horizon.path' => 'admin/horizon', 'dynamic-throttle.except' => ['admin/*']]],
    'a pattern with a leading slash' => [['horizon.path' => 'admin/horizon', 'dynamic-throttle.except' => ['/admin/horizon', '/admin/horizon/*']]],
    'no package installed' => [[]],
]);

it('AC-9: warns about a package whose path except covers only in part', function (): void {
    Health::passing();
    config()->set(['horizon.path' => 'horizon', 'dynamic-throttle.except' => ['horizon', 'horizon/api/*']]);

    [$status, $message] = Health::check('except');

    expect($status)->toBe('warning')
        ->and($message)->toContain('"horizon/*"');
});

it('AC-10: warns about a route that runs both throttle and dynamic.throttle, naming its methods and URI', function (): void {
    Health::passing();
    Route::middleware(['throttle:60,1', 'dynamic.throttle'])->post('api/orders', fn (): string => 'ok');

    [$status, $message] = Health::check('two-limiters');

    expect($status)->toBe('warning')
        ->and($message)->toContain('POST api/orders');
});

it('AC-10: names the first ten such routes, then how many more', function (): void {
    Health::passing();
    healthTwelveLimitedRoutes();

    $message = Health::message('two-limiters');

    expect($message)->toContain('GET|HEAD api/r1,')->toContain('GET|HEAD api/r10')->toContain('and 2 more')
        ->not->toContain('api/r11')->not->toContain('api/r12');
});

it('AC-10: counts a route under the global dynamic.throttle', function (): void {
    Health::passing();
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Route::middleware('throttle:60,1')->get('api/limited', fn (): string => 'ok');

    expect(Health::check('two-limiters'))->toMatchArray([0 => 'warning'])
        ->and(Health::message('two-limiters'))->toContain('GET|HEAD api/limited');
});

it('AC-10: is ok for a route with throttle alone, or one under except', function (): void {
    Health::passing();
    Route::middleware('throttle:60,1')->get('api/limited', fn (): string => 'ok');
    Route::middleware(['throttle:60,1', 'dynamic.throttle'])->get('up', fn (): string => 'ok');

    expect(Health::status('two-limiters'))->toBe('ok');
});

it('AC-11: warns about a route where authentication runs after the global dynamic.throttle, when a user rule exists', function (): void {
    Health::passing();
    Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Route::middleware('auth')->get('api/account', fn (): string => 'ok');

    [$status, $message] = Health::check('middleware-order');

    expect($status)->toBe('warning')
        ->and($message)->toContain('GET|HEAD api/account');
});

it('AC-11: is ok where the priority list runs auth before dynamic.throttle, in any order the route lists them', function (): void {
    Health::passing();
    Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Route::middleware(['dynamic.throttle', 'auth'])->get('api/account', fn (): string => 'ok');

    expect(Health::status('middleware-order'))->toBe('ok');
});

it('AC-11: is ok without a user rule, or with scopes.user.guards set, which looks the user up', function (Closure $setup, string $said): void {
    Health::passing();
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Route::middleware('auth')->get('api/account', fn (): string => 'ok');
    $setup();

    [$status, $message] = Health::check('middleware-order');

    expect($status)->toBe('ok')
        ->and($message)->toContain($said);
})->with([
    'no user rule' => [fn (): null => null, 'no user rule'],
    'guards set' => [function (): void {
        Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
        config()->set('dynamic-throttle.scopes.user.guards', ['web']);
    }, 'scopes.user.guards'],
]);

it('AC-11: is skipped without a manifest, whose rules it needs', function (): void {
    Health::passing();
    Cache::store('health')->forget(app(KeyBuilder::class)->manifest());

    expect(Health::status('middleware-order'))->toBe('skipped');
});
