<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Dashboard\Authorize;
use Afzal\DynamicThrottle\Dashboard\DashboardRoutes;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
| SPEC-017 AC-20 and AC-21: every endpoint for a guest, a viewer and a manager,
| with both gates defined (the roadmap's Phase 4 exit), and explain as the one
| POST a viewer may send. Each row is a route's name, its method, its path, with
| {rule} and {exemption} for ones that exist, and a valid body.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    $this->rule = Throttling::rule();
    $this->exemption = Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7']);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/** @var array<string, array{string, string, array<string, mixed>}> */
const API_READS = [
    'dynamic-throttle.api.me' => ['GET', 'me', []],
    'dynamic-throttle.api.settings' => ['GET', 'settings', []],
    'dynamic-throttle.api.rules.index' => ['GET', 'rules', []],
    'dynamic-throttle.api.rules.show' => ['GET', 'rules/{rule}', []],
    'dynamic-throttle.api.rules.counts' => ['GET', 'rules/counts', []],
    'dynamic-throttle.api.rules.offenders' => ['GET', 'rules/{rule}/offenders', []],
    'dynamic-throttle.api.exemptions.index' => ['GET', 'exemptions', []],
    'dynamic-throttle.api.metrics' => ['GET', 'metrics', []],
    'dynamic-throttle.api.health' => ['GET', 'health', []],
    'dynamic-throttle.api.audit' => ['GET', 'audit', []],
    'dynamic-throttle.api.explain' => ['POST', 'explain', ['method' => 'GET', 'path' => '/api/orders']],
];

/** @var array<string, array{string, string, array<string, mixed>}> */
const API_CHANGES = [
    'dynamic-throttle.api.settings.bypass' => ['PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true]],
    'dynamic-throttle.api.settings.scale' => ['PUT', 'settings/scale', ['factor' => '2']],
    'dynamic-throttle.api.rules.store' => ['POST', 'rules', ['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]],
    'dynamic-throttle.api.rules.update' => ['PATCH', 'rules/{rule}', ['max_attempts' => 5]],
    'dynamic-throttle.api.rules.destroy' => ['DELETE', 'rules/{rule}', ['confirm' => true]],
    'dynamic-throttle.api.exemptions.store' => ['POST', 'exemptions', ['subject' => 'ip', 'value' => '10.0.0.1']],
    'dynamic-throttle.api.exemptions.destroy' => ['DELETE', 'exemptions/{exemption}', []],
];

/**
 * What the tables hold, to show that a refused change changed nothing.
 *
 * @return list<int>
 */
function storedCounts(): array
{
    return [Rule::query()->count(), Exemption::query()->count(), Setting::query()->count(), AuditEntry::query()->count()];
}

it('AC-20: covers every route of the API', function (): void {
    $names = collect(Route::getRoutes()->getRoutes())
        ->map(fn (LaravelRoute $route): ?string => $route->getName())
        ->filter(fn (?string $name): bool => is_string($name) && str_starts_with($name, 'dynamic-throttle.api.'))
        ->sort()->values()->all();
    $covered = array_keys([...API_READS, ...API_CHANGES]);
    sort($covered);

    expect($names)->toBe($covered);
});

it('AC-20: refuses a guest everywhere', function (string $method, string $uri, array $data): void {
    $before = storedCounts();
    $response = DashboardApi::send($this, $method, str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri), $data);

    expect([$response->status(), storedCounts()])->toBe([403, $before]);
})->with([...API_READS, ...API_CHANGES]);

it('AC-20: lets a viewer read and explain', function (string $method, string $uri, array $data): void {
    $response = DashboardApi::send($this, $method, str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri), $data, DashboardApi::viewer());

    expect($response->status())->toBe(200);
})->with(API_READS);

it('AC-20: refuses a viewer every change, and changes nothing', function (string $method, string $uri, array $data): void {
    $before = storedCounts();
    $response = DashboardApi::send($this, $method, str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri), $data, DashboardApi::viewer());

    expect([$response->status(), storedCounts()])->toBe([403, $before]);
})->with(API_CHANGES);

it('AC-20: lets a manager use every endpoint', function (string $method, string $uri, array $data): void {
    $response = DashboardApi::send($this, $method, str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri), $data, DashboardApi::manager());

    expect($response->status())->toBeIn([200, 201]);
})->with([...API_READS, ...API_CHANGES]);

it('AC-21: lets only explain take a non-GET request with the view gate', function (): void {
    $inspect = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => in_array(Authorize::class.':inspect', $route->gatherMiddleware(), true))
        ->map(fn (LaravelRoute $route): ?string => $route->getName())
        ->values()->all();

    expect($inspect)->toBe(['dynamic-throttle.api.explain']);
});

it('AC-21: lets inspect take a POST from a viewer, and nothing else that writes', function (): void {
    Route::middleware(['web', ...DashboardRoutes::middleware('inspect')])
        ->match(['POST', 'PUT', 'PATCH', 'DELETE'], 'dynamic-throttle/api/test-inspect', fn (): array => ['ok' => true]);

    $statuses = array_map(
        fn (string $method): int => DashboardApi::send($this, $method, 'test-inspect', [], DashboardApi::viewer())->status(),
        ['POST', 'PUT', 'PATCH', 'DELETE'],
    );

    expect($statuses)->toBe([200, 403, 403, 403])
        ->and(DashboardApi::send($this, 'POST', 'test-inspect', [], new TestUser(['id' => 3]))->status())->toBe(403);
});
