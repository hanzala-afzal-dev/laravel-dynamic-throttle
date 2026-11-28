<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;

/*
| Issue #209, SPEC-017 AC-28, on a real MySQL and PostgreSQL client: the package's
| connection points at a port nothing listens on, so the server refuses it, and the
| dashboard's API answers 503 with its plain message. Each driver words the refusal
| its own way, and PostgreSQL's is not in Laravel 12's list of lost connections.
*/

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('issue #209: answers 503 when the server refuses the package\'s connection', function (string $driver, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    DashboardApi::prepare();
    // Port 1 on this machine: nothing listens there, so the connection is refused at once.
    config()->set('database.connections.refused', [
        'driver' => $driver,
        'host' => '127.0.0.1',
        'port' => 1,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => '',
    ]);
    config()->set('dynamic-throttle.database.connection', 'refused');

    $read = DashboardApi::send($this, 'GET', 'rules', [], DashboardApi::manager());
    $change = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true], DashboardApi::manager());

    expect([$read->status(), $change->status()])->toBe([503, 503])
        ->and($read->json('message'))->toStartWith('The database cannot be reached');
})->with([
    'MySQL' => ['mysql', 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', 'pdo_pgsql'],
]);
