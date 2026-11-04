<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-034 AC-24 and AC-25: the dashboard check knows about links. It never reads the
| database (SPEC-016 AC-21), so a missing links table is for the command and the
| link's page to say, not this check. Every other check
| passes before each test changes one thing (Health::passing()), no gate is defined,
| links are on, and the app runs in production.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    // Booted first: booting again would drop the route Health::passing() attaches the middleware to.
    Dashboard::boot(['links' => true]);
    Health::passing(gates: false);
    Dashboard::environment('production');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const LINKS_ONLY = 'Only links from dynamic-throttle:link open the dashboard: the app defines neither viewDynamicThrottle nor manageDynamicThrottle.';
const LINKS_TOO = ' Links from dynamic-throttle:link open it too.';

it('AC-24: is ok outside local with no gate when links are on, as only links open the dashboard, and --strict passes', function (): void {
    [$exitCode] = Health::report(['--strict' => true]);

    expect(Health::check('dashboard'))->toBe(['ok', LINKS_ONLY])
        ->and($exitCode)->toBe(0);
});

it('AC-24: names the gates that decide, and says links open the dashboard too', function (bool $view, bool $manage, string $message): void {
    Dashboard::gates($view ? fn (): bool => true : null, $manage ? fn (): bool => true : null);

    expect(Health::check('dashboard'))->toBe(['ok', $message.LINKS_TOO]);
})->with([
    'both gates' => [true, true, 'viewDynamicThrottle and manageDynamicThrottle decide who opens the dashboard.'],
    'the manager gate' => [false, true, 'manageDynamicThrottle decides who opens the dashboard, and who changes things there.'],
    'the viewer gate' => [true, false, 'viewDynamicThrottle decides who opens the dashboard. Nobody can change anything there until the app defines manageDynamicThrottle.'],
]);

it('AC-24: says what it said before while links are off', function (): void {
    Dashboard::boot(['links' => false]);
    Dashboard::environment('production');

    expect(Health::check('dashboard'))->toBe(['warning', 'Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle, or turn the dashboard off with DYNAMIC_THROTTLE_DASHBOARD=false: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/dashboard#who-gets-in.']);
});

it('AC-24: keeps the local environment\'s message, where anyone gets in anyway', function (): void {
    Dashboard::environment('local');

    expect(Health::check('dashboard'))->toBe(['ok', 'In the local environment, anyone can open the dashboard, guests too. Elsewhere, nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle.']);
});

it('AC-25: warns when links are on but the dashboard\'s middleware starts no session', function (array $middleware): void {
    Dashboard::boot(['links' => true, 'middleware' => $middleware]);
    Dashboard::environment('production');

    expect(Health::check('dashboard'))->toBe(['warning', 'Links are on, but the dashboard\'s middleware starts no session, so a link cannot sign anyone in: add the web group to dynamic-throttle.dashboard.middleware.']);
})->with([
    'no middleware' => [[]],
    'the api group' => [['api']],
]);

it('AC-25: finds the session in the web group, as the default middleware has it', function (): void {
    Dashboard::boot(['links' => true, 'middleware' => ['web', 'auth.basic']]);
    Dashboard::environment('production');

    expect(Health::check('dashboard'))->toBe(['ok', LINKS_ONLY]);
});
