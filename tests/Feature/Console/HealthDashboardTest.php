<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/*
| SPEC-033: the dashboard check. Outside the local environment, nobody can open the
| dashboard until the app defines viewDynamicThrottle or manageDynamicThrottle, and
| nothing said so. Every other check passes before each test changes one thing
| (Health::passing()), and no gate is defined.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing(gates: false);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const DASHBOARD_WARNING = 'Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle, or turn the dashboard off with DYNAMIC_THROTTLE_DASHBOARD=false (docs/guide/dashboard.md#who-gets-in).';

const DASHBOARD_BOTH = 'viewDynamicThrottle and manageDynamicThrottle decide who opens the dashboard.';

const DASHBOARD_ME = '/dynamic-throttle/api/me';

it('AC-1: warns outside local when the app defines no gate, and --strict exits 1 for it', function (): void {
    Dashboard::environment('production');

    [$exitCode, $report] = Health::report(['--strict' => true]);
    $problems = array_values(array_filter($report['checks'], fn (array $check): bool => ! in_array($check['status'], ['ok', 'skipped'], true)));

    expect($problems)->toBe([['check' => 'dashboard', 'status' => 'warning', 'message' => DASHBOARD_WARNING]])
        ->and($exitCode)->toBe(1);
});

it('AC-1: says what the dashboard does there: it turns guests and users away', function (string $environment): void {
    Dashboard::boot();
    Dashboard::environment($environment);

    expect(Health::check('dashboard'))->toBe(['warning', DASHBOARD_WARNING])
        ->and($this->getJson(DASHBOARD_ME)->status())->toBe(403)
        ->and($this->actingAs(new TestUser(['id' => 1]))->getJson(DASHBOARD_ME)->status())->toBe(403);
})->with(['production', 'staging', 'testing']);

it('AC-2: is ok in local without a gate, where anyone gets in, and says that nobody will elsewhere', function (): void {
    Dashboard::boot();
    Dashboard::environment('local');

    expect(Health::check('dashboard'))->toBe(['ok', 'In the local environment, anyone can open the dashboard, guests too. Elsewhere, nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle.'])
        ->and($this->getJson(DASHBOARD_ME)->status())->toBe(200);
});

it('AC-3: is ok when the app defines a gate, naming the gates that decide', function (bool $view, bool $manage, string $message): void {
    Dashboard::environment('production');
    Dashboard::gates($view ? fn (): bool => true : null, $manage ? fn (): bool => true : null);

    expect(Health::check('dashboard'))->toBe(['ok', $message]);
})->with([
    'both gates' => [true, true, DASHBOARD_BOTH],
    'manageDynamicThrottle alone' => [false, true, 'manageDynamicThrottle decides who opens the dashboard, and who changes things there.'],
    'viewDynamicThrottle alone' => [true, false, 'viewDynamicThrottle decides who opens the dashboard. Nobody can change anything there until the app defines manageDynamicThrottle.'],
]);

it('AC-4: warns with a Gate::before callback alone, which the dashboard ignores while no gate is defined', function (): void {
    Dashboard::boot();
    Dashboard::environment('production');
    Gate::before(fn (): bool => true);

    expect(Health::check('dashboard'))->toBe(['warning', DASHBOARD_WARNING])
        ->and($this->actingAs(new TestUser(['id' => 1]))->getJson(DASHBOARD_ME)->status())->toBe(403);
});

it('AC-5: is skipped when the dashboard is off', function (): void {
    config()->set('dynamic-throttle.dashboard.enabled', false);
    Dashboard::environment('production');

    expect(Health::check('dashboard'))->toBe(['skipped', 'The dashboard is off (dynamic-throttle.dashboard.enabled).']);
});

it('AC-6: is listed after apcu', function (): void {
    expect(array_keys(Health::checks()))->toBe(Health::CHECKS)
        ->and(array_slice(Health::CHECKS, 5, 2))->toBe(['apcu', 'dashboard']);
});

it('AC-6: shows on the dashboard, whose GET api/health runs the same checks', function (): void {
    DashboardApi::prepare();
    Health::passing();

    $response = DashboardApi::send($this, 'GET', 'health', user: DashboardApi::viewer());
    $dashboard = collect($response->json('checks'))->firstWhere('check', 'dashboard');

    expect([$response->status(), $dashboard])->toBe([200, ['check' => 'dashboard', 'status' => 'ok', 'message' => DASHBOARD_BOTH]]);
});
