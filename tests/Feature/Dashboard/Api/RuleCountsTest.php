<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Facades\DB;

/*
| SPEC-021 AC-1: the rule counts the Overview shows.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-1: counts the enabled rules by mode and the disabled rules, in one query', function (): void {
    Throttling::rule(['name' => 'A']);
    Throttling::rule(['name' => 'B']);
    Throttling::rule(['name' => 'C', 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'D', 'enabled' => false]);
    Throttling::rule(['name' => 'E', 'enabled' => false, 'mode' => RuleMode::DryRun]);
    DB::enableQueryLog();

    $response = DashboardApi::send($this, 'GET', 'rules/counts', user: DashboardApi::viewer());
    $queries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'dynamic_throttle_rules'))->count();

    expect([$response->status(), $response->json(), $queries])->toBe([200, ['enforcing' => 2, 'dry_run' => 1, 'disabled' => 2], 1]);
});

it('AC-1: answers 0 for a kind with no rule', function (): void {
    expect(DashboardApi::send($this, 'GET', 'rules/counts', user: DashboardApi::viewer())->json())
        ->toBe(['enforcing' => 0, 'dry_run' => 0, 'disabled' => 0]);
});

it('AC-1: refuses a guest in production', function (): void {
    expect(DashboardApi::send($this, 'GET', 'rules/counts')->status())->toBe(403);
});
