<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
| SPEC-019 AC-6, the roadmap's Phase 4 exit: every change route of the API writes
| exactly one audit entry, naming what it changed, and none when the same request
| changes nothing. Each row is a route's name, its method, its path with {rule}
| and {exemption} for ones that exist, a valid body, the entry's action and
| target, with {new-rule} and {new-exemption} for what it creates, and whether
| sending it again changes nothing. The clock stands still.
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

/** @var array<string, array{string, string, array<string, mixed>, string, string, bool}> */
const API_CHANGE_ENTRIES = [
    'dynamic-throttle.api.settings.bypass' => ['PUT', 'settings/bypass', ['state' => 'on', 'for' => '1h', 'confirm' => true], 'settings.bypass', 'setting:bypass_all', true],
    'dynamic-throttle.api.settings.scale' => ['PUT', 'settings/scale', ['factor' => '2'], 'settings.scale', 'setting:scale_factor', true],
    'dynamic-throttle.api.rules.store' => ['POST', 'rules', ['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60], 'rule.create', 'rule:{new-rule}', false],
    'dynamic-throttle.api.rules.update' => ['PATCH', 'rules/{rule}', ['max_attempts' => 5], 'rule.update', 'rule:{rule}', true],
    'dynamic-throttle.api.rules.destroy' => ['DELETE', 'rules/{rule}', ['confirm' => true], 'rule.delete', 'rule:{rule}', true],
    'dynamic-throttle.api.exemptions.store' => ['POST', 'exemptions', ['subject' => 'ip', 'value' => '10.0.0.1'], 'exemption.create', 'exemption:{new-exemption}', false],
    'dynamic-throttle.api.exemptions.destroy' => ['DELETE', 'exemptions/{exemption}', [], 'exemption.delete', 'exemption:{exemption}', true],
];

it('AC-6: covers every change route of the API', function (): void {
    $changes = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'dynamic-throttle.api.')
            && array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) !== []
            // Explain writes nothing (SPEC-017 decision 2).
            && $route->getName() !== 'dynamic-throttle.api.explain')
        ->map(fn (LaravelRoute $route): string => (string) $route->getName())
        ->sort()->values()->all();
    $covered = array_keys(API_CHANGE_ENTRIES);
    sort($covered);

    expect($changes)->toBe($covered);
});

it('AC-6: writes exactly one entry for a change, naming it, and none when it changes nothing', function (string $method, string $uri, array $data, string $action, string $target, bool $repeat): void {
    $path = str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri);

    $response = DashboardApi::send($this, $method, $path, $data, DashboardApi::manager());
    $entries = Settings::audit();
    $named = str_replace(
        ['{rule}', '{exemption}', '{new-rule}', '{new-exemption}'],
        [(string) $this->rule->id, (string) $this->exemption->id, (string) Rule::query()->max('id'), (string) Exemption::query()->max('id')],
        $target,
    );

    expect($response->status())->toBeIn([200, 201])
        ->and(array_map(fn (array $entry): array => [$entry['action'], $entry['target'], $entry['actor']], $entries))
        ->toBe([[$action, $named, DashboardApi::managerActor()]]);

    if ($repeat) {
        DashboardApi::send($this, $method, $path, $data, DashboardApi::manager());

        expect(Settings::audit())->toHaveCount(1);
    }
})->with(API_CHANGE_ENTRIES);
