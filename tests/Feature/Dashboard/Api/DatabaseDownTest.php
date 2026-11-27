<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Database\SQLiteConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;

/*
| Issue #209, SPEC-017 AC-28. When the database cannot be reached, each endpoint that
| reads or writes it answers 503 with a plain message, which names no host, no SQL
| and no driver error, and the error is reported to the app's handler. Any other
| database error stays a 500, so a mistake never looks like an outage.
|
| The package's connection here is one whose PDO throws the driver's own error, on
| every try, so Laravel's reconnect fails too, as it does when the server is down.
| tests/Drivers/DashboardDatabaseDownTest.php refuses a real MySQL and PostgreSQL.
*/

const DATABASE_DOWN = 'The database cannot be reached, so the dashboard can neither show nor change what it keeps there. Requests never read the database: they apply the rules compiled into the cache.';

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->rule = Throttling::rule();
    $this->exemption = Exemption::query()->create(['subject' => ExemptionSubject::Ip, 'value' => '203.0.113.7']);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Points the package's tables at a connection whose every try fails with $error.
 */
function databaseDown(string $error): void
{
    DB::extend('dynamic-throttle-down', fn (array $config, string $name): SQLiteConnection => new SQLiteConnection(
        fn (): never => throw new PDOException($error),
        ':memory:',
        '',
        [...$config, 'name' => $name],
    ));
    config()->set('database.connections.dynamic-throttle-down', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'dynamic-throttle-down');
}

/** The errors of a database that cannot be reached: MySQL's, PostgreSQL's, and the SQL standard's class 08. */
const UNREACHABLE = [
    'MySQL refuses the connection' => ['SQLSTATE[HY000] [2002] Connection refused'],
    'PostgreSQL refuses the connection' => ["SQLSTATE[08006] [7] connection to server at \"db.internal\", port 5432 failed: Connection refused\n\tIs the server running on that host and accepting TCP/IP connections?"],
    'a connection exception of SQLSTATE class 08' => ['SQLSTATE[08001]: Client unable to establish connection'],
];

/** Each endpoint that reads or writes the package's tables, with a valid body. */
const READS_THE_DATABASE = [
    'GET api/settings' => ['GET', 'settings', []],
    'GET api/rules' => ['GET', 'rules', []],
    'GET api/rules/{rule}' => ['GET', 'rules/{rule}', []],
    'GET api/rules/counts' => ['GET', 'rules/counts', []],
    'GET api/rules/{rule}/offenders' => ['GET', 'rules/{rule}/offenders', []],
    'GET api/exemptions' => ['GET', 'exemptions', []],
    'GET api/metrics' => ['GET', 'metrics', []],
    'GET api/metrics?period=week' => ['GET', 'metrics?period=week', []],
    'GET api/audit' => ['GET', 'audit', []],
    'PUT api/settings/bypass' => ['PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true]],
    'PUT api/settings/scale' => ['PUT', 'settings/scale', ['factor' => '2']],
    'POST api/rules' => ['POST', 'rules', ['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]],
    'PATCH api/rules/{rule}' => ['PATCH', 'rules/{rule}', ['max_attempts' => 5]],
    'DELETE api/rules/{rule}' => ['DELETE', 'rules/{rule}', ['confirm' => true]],
    'POST api/exemptions' => ['POST', 'exemptions', ['subject' => 'ip', 'value' => '10.0.0.1']],
    'DELETE api/exemptions/{exemption}' => ['DELETE', 'exemptions/{exemption}', []],
];

it('issue #209: answers 503 with a plain message when the database cannot be reached, and reports the error', function (string $method, string $uri, array $data, string $error): void {
    Exceptions::fake();
    databaseDown($error);

    $response = DashboardApi::send($this, $method, str_replace(['{rule}', '{exemption}'], [(string) $this->rule->id, (string) $this->exemption->id], $uri), $data, DashboardApi::manager());

    expect([$response->status(), $response->json()])->toBe([503, ['message' => DATABASE_DOWN]]);
    Exceptions::assertReported(fn (Throwable $exception): bool => str_contains($exception->getMessage(), $error));
})->with(READS_THE_DATABASE)->with(UNREACHABLE);

it('issue #209: still answers what needs no database', function (string $method, string $uri, array $data): void {
    databaseDown('SQLSTATE[HY000] [2002] Connection refused');

    expect(DashboardApi::send($this, $method, $uri, $data, DashboardApi::manager())->status())->toBe(200);
})->with([
    'GET api/me' => ['GET', 'me', []],
    'GET api/config' => ['GET', 'config', []],
    'GET api/health' => ['GET', 'health', []],
    'GET api/feed' => ['GET', 'feed', []],
    'POST api/explain' => ['POST', 'explain', ['method' => 'GET', 'path' => '/api/orders']],
]);

it('issue #209: leaves any other database error a 500, so a mistake never looks like an outage', function (): void {
    Exceptions::fake();
    databaseDown('SQLSTATE[42000]: Syntax error or access violation: 1064 You have an error in your SQL syntax');

    $response = DashboardApi::send($this, 'GET', 'rules', [], DashboardApi::manager());

    expect($response->status())->toBe(500);
});
