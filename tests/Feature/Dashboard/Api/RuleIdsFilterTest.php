<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;

/*
| SPEC-023 AC-9: GET api/rules?ids=, so the Exemptions page names the rules beyond
| the first 100 in one request.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->ids = array_map(fn (string $name): int => Throttling::rule(['name' => $name, 'pattern' => strtolower($name), 'priority' => 10])->id, ['Login', 'Orders', 'Exports']);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-9: lists the rules with the given ids, in evaluation order', function (): void {
    [$login, , $exports] = $this->ids;
    $response = DashboardApi::send($this, 'GET', "rules?ids={$exports},{$login},999", user: DashboardApi::viewer());

    expect([$response->status(), array_column($response->json('data'), 'name')])->toBe([200, ['Login', 'Exports']]);
});

it('AC-9: refuses ids that are not a list of up to 100 rule ids', function (string $ids): void {
    $response = DashboardApi::send($this, 'GET', "rules?{$ids}", user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors') ?? [])])->toBe([422, ['ids']]);
})->with([
    'a word' => ['ids=login'],
    'zero' => ['ids=0'],
    'an empty id' => ['ids=1,,2'],
    'a leading zero' => ['ids=01'],
    'an array' => ['ids[]=1'],
    '101 ids' => ['ids='.implode(',', range(1, 101))],
]);
