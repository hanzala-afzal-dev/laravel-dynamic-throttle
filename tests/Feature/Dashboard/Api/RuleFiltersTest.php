<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;

/*
| SPEC-022 AC-1: the search and the filters of GET api/rules, for the Rules page.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    Throttling::rule(['name' => 'Login', 'pattern' => 'login', 'priority' => 1]);
    Throttling::rule(['name' => 'API', 'pattern' => 'api/*', 'priority' => 2]);
    Throttling::rule(['name' => 'Catalog', 'pattern' => 'shop/*', 'priority' => 3, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Old blog', 'pattern' => 'blog/*', 'priority' => 4, 'enabled' => false]);
    Throttling::rule(['name' => 'Exports', 'pattern' => 'reports/*/export', 'priority' => 5, 'enabled' => false, 'mode' => RuleMode::DryRun]);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The names the API lists for a query string.
 *
 * @return list<string>
 */
function ruleNames(TestCase $test, string $query): array
{
    return array_column(DashboardApi::send($test, 'GET', "rules?{$query}", user: DashboardApi::viewer())->json('data'), 'name');
}

it('AC-1: searches the name and the pattern, ignoring case', function (): void {
    expect([ruleNames($this, 'search=log'), ruleNames($this, 'search=LOG'), ruleNames($this, 'search=export'), ruleNames($this, 'search=shop')])
        ->toBe([['Login', 'Catalog', 'Old blog'], ['Login', 'Catalog', 'Old blog'], ['Exports'], ['Catalog']]);
});

it('AC-1: filters by status and by mode, and combines them with the search', function (): void {
    expect([
        ruleNames($this, 'enabled=false'),
        ruleNames($this, 'enabled=true'),
        ruleNames($this, 'mode=dry_run'),
        ruleNames($this, 'mode=enforce&enabled=true'),
        ruleNames($this, 'search=o&enabled=false&mode=dry_run'),
    ])->toBe([
        ['Old blog', 'Exports'],
        ['Login', 'API', 'Catalog'],
        ['Catalog', 'Exports'],
        ['Login', 'API'],
        ['Exports'],
    ]);
});

it('AC-1: counts and pages the filtered rules, with the filters in the next page\'s link', function (): void {
    $response = DashboardApi::send($this, 'GET', 'rules?enabled=false', user: DashboardApi::viewer());

    expect([$response->json('total'), $response->json('last_page'), $response->json('first_page_url')])
        ->toBe([2, 1, url('/dynamic-throttle/api/rules?enabled=false&page=1')]);
});

it('AC-1: refuses a filter it does not know the value of', function (string $query, string $field): void {
    $response = DashboardApi::send($this, 'GET', "rules?{$query}", user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, [$field]]);
})->with([
    ['enabled=maybe', 'enabled'],
    ['mode=fast', 'mode'],
    ['search[]=x', 'search'],
]);
