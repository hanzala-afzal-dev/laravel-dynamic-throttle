<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;

/*
| SPEC-020 AC-8: api/me also says the app's environment, which the dashboard's
| header shows, so an admin sees at once whether this is production.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-8: says what the user may do, and the app\'s environment', function (string $environment): void {
    Dashboard::environment($environment);

    $response = DashboardApi::send($this, 'GET', 'me', user: DashboardApi::manager());

    expect($response->json())->toBe(['can' => ['view' => true, 'manage' => true], 'environment' => $environment]);
})->with(['production', 'staging']);
