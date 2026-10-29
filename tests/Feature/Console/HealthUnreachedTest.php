<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Routing\RouteCollection;
use Illuminate\Routing\Router;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

/*
| SPEC-035: the unreached check, which warns about a rule that matches routes
| of the app, none of which limits it: they do not run dynamic.throttle, or
| except covers their paths (issue #149). Every check passes before each test
| changes one thing (Health::passing()): the rule "API", on api/*, reaches
| api/x, which runs dynamic.throttle.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const UNREACHED_ADVICE = 'Attach dynamic.throttle to those routes\' groups, such as web for login and sign-up forms, or list the groups in dynamic-throttle.middleware.auto_attach.';

const UNREACHED_OK = 'Every rule that matches a route of the app matches one that dynamic.throttle limits.';

/** The rule from the dashboard's Login template, which the new-user test made. */
function loginRule(array $overrides = []): Rule
{
    return Throttling::rule(['name' => 'Login', 'method' => 'POST', 'pattern' => 'login', 'max_attempts' => 5, ...$overrides]);
}

it('AC-1: warns about a rule that matches only a web route while only api runs dynamic.throttle, and --strict exits 1', function (): void {
    Route::middleware('web')->post('login', fn (): string => 'ok');
    $login = loginRule();

    expect(Health::check('unreached'))->toBe(['warning', "1 rule matches only routes that do not run dynamic.throttle, or that except covers, so it never applies: rule {$login->id}, Login, on POST login. ".UNREACHED_ADVICE])
        ->and(Artisan::call('dynamic-throttle:health', ['--strict' => true]))->toBe(1);
});

it('AC-2: is ok once web runs dynamic.throttle too', function (): void {
    app(Router::class)->pushMiddlewareToGroup('web', 'dynamic.throttle');
    Route::middleware('web')->post('login', fn (): string => 'ok');
    loginRule();

    expect(Health::check('unreached'))->toBe(['ok', UNREACHED_OK]);
});

it('AC-3: is ok for a rule that one of its routes limits, though another does not', function (): void {
    Throttling::route('reports/a');
    Route::get('reports/b', fn (): string => 'ok');
    Throttling::rule(['name' => 'Reports', 'pattern' => 'reports/*']);

    expect(Health::status('unreached'))->toBe('ok');
});

it('AC-4: warns about a rule whose only route except covers, though the route runs dynamic.throttle', function (): void {
    Throttling::route('up');
    $up = Throttling::rule(['name' => 'Health', 'pattern' => 'up']);

    expect(Health::message('unreached'))->toContain("so it never applies: rule {$up->id}, Health, on GET|HEAD up. ");
});

it('AC-5: is ok for a rule that matches no route, by its pattern, its method, or a parameter\'s value', function (array $rule): void {
    Route::get('plain', fn (): string => 'ok');
    Route::get('api/users/{user}/posts', fn (): string => 'ok');
    Throttling::rule(['name' => 'Elsewhere', ...$rule]);

    expect(Health::check('unreached'))->toBe(['ok', UNREACHED_OK]);
})->with([
    'a path no route has' => [['pattern' => 'nothing/*']],
    'a method the route does not answer' => [['pattern' => 'plain', 'method' => 'POST']],
    'a parameter\'s value' => [['pattern' => 'api/users/42/*']],
]);

it('AC-6: matches a route\'s parameters with the rule\'s wildcards, and without its optional ones', function (string $uri, string $pattern): void {
    Route::get($uri, fn (): string => 'ok');
    $rule = Throttling::rule(['name' => 'Parameters', 'pattern' => $pattern]);

    expect(Health::check('unreached'))->toBe(['warning', "1 rule matches only routes that do not run dynamic.throttle, or that except covers, so it never applies: rule {$rule->id}, Parameters, on GET|HEAD {$uri}. ".UNREACHED_ADVICE]);
})->with([
    'a parameter' => ['users/{user}/export', 'users/*/export'],
    'an optional parameter, left out' => ['posts/{post?}', 'posts'],
]);

it('AC-7: matches a route\'s methods as the middleware does', function (string $method, string $status): void {
    Route::get('plain', fn (): string => 'ok');
    Throttling::rule(['name' => 'Plain', 'pattern' => 'plain', 'method' => $method]);

    expect(Health::status('unreached'))->toBe($status);
})->with([
    'GET, on a GET|HEAD route' => ['GET', 'warning'],
    'any method' => ['*', 'warning'],
    'POST, on a GET|HEAD route' => ['POST', 'ok'],
]);

it('AC-8: checks a dry-run rule as an enforcing one, and never names a disabled rule', function (): void {
    Route::get('plain', fn (): string => 'ok');
    $dryRun = Throttling::rule(['name' => 'Trying', 'pattern' => 'plain', 'mode' => 'dry_run']);
    Throttling::rule(['name' => 'Off', 'pattern' => 'plain', 'enabled' => false]);

    expect(Health::message('unreached'))->toContain("rule {$dryRun->id}, Trying, on GET|HEAD plain")
        ->not->toContain('Off');
});

it('AC-9: names the first ten rules, then how many more, and counts them all', function (): void {
    foreach (range(1, 12) as $n) {
        Route::get("pages/p{$n}", fn (): string => 'ok');
        Throttling::rule(['name' => "Page {$n}", 'pattern' => "pages/p{$n}"]);
    }

    $message = Health::message('unreached');

    expect($message)->toStartWith('12 rules match only routes that do not run dynamic.throttle, or that except covers, so they never apply: ')
        ->toContain('Page 10, on GET|HEAD pages/p10; and 2 more. ')
        ->not->toContain('Page 11,');
});

it('AC-10: is ok for a rule on any route outside except when dynamic.throttle is global middleware', function (): void {
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Route::get('plain', fn (): string => 'ok');
    Throttling::rule(['name' => 'Plain', 'pattern' => 'plain']);

    expect(Health::status('unreached'))->toBe('ok');
});

it('AC-11: is skipped when no route runs dynamic.throttle, which the attached check reports', function (): void {
    app(Router::class)->setRoutes(new RouteCollection);
    Route::get('plain', fn (): string => 'ok');
    Throttling::rule(['name' => 'Plain', 'pattern' => 'plain']);

    expect(Health::check('unreached'))->toBe(['skipped', 'No route runs dynamic.throttle: see the attached check.']);
});

it('AC-11: is skipped without a manifest, with its problem', function (): void {
    Cache::store('health')->forget(app(KeyBuilder::class)->manifest());

    [$status, $message] = Health::check('unreached');

    expect([$status, $message])->toBe(['skipped', Health::message('middleware-order')])
        ->and($message)->not->toBe('');
});

it('AC-11: is ok without a rule', function (): void {
    Rule::query()->get()->each->delete();

    expect(Health::check('unreached'))->toBe(['ok', 'There is no rule.']);
});

it('AC-12: is listed after attached', function (): void {
    expect(array_keys(Health::checks()))->toBe(Health::CHECKS)
        ->and(array_slice(Health::CHECKS, 7, 2))->toBe(['attached', 'unreached']);
});

it('AC-12: shows on the dashboard, whose GET api/health runs the same checks', function (): void {
    DashboardApi::prepare();
    Health::passing();

    $response = DashboardApi::send($this, 'GET', 'health', user: DashboardApi::viewer());

    expect([$response->status(), collect($response->json('checks'))->firstWhere('check', 'unreached')])
        ->toBe([200, ['check' => 'unreached', 'status' => 'ok', 'message' => UNREACHED_OK]]);
});
