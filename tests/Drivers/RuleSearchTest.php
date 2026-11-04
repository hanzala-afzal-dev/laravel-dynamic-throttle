<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-022 AC-1 and AC-16 on the docker compose MySQL and PostgreSQL, whose LIKE
| differs from SQLite's: a backslash escapes there, and PostgreSQL's LIKE heeds
| case. Each test gets a random table prefix, so parallel runs never share a table.
*/

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('SPEC-022 AC-16: searches ignoring case, with %, _ and \\ as themselves', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.search-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => 'p'.bin2hex(random_bytes(4)).'_',
    ]);
    config()->set('dynamic-throttle.database.connection', "search-{$driver}");
    DashboardApi::prepare();

    try {
        Throttling::rule(['name' => 'Login', 'pattern' => 'login', 'priority' => 1]);
        Throttling::rule(['name' => 'Catalog', 'pattern' => 'shop/*', 'priority' => 2]);
        Throttling::rule(['name' => '100% off', 'pattern' => 'sale/*', 'priority' => 3]);
        Throttling::rule(['name' => 'Snakes', 'pattern' => 'snake_case/*', 'priority' => 4]);
        Throttling::rule(['name' => 'Back\\slash', 'pattern' => 'back/*', 'priority' => 5]);
        $names = fn (string $query): array => array_column(DashboardApi::send($this, 'GET', "rules?{$query}", user: DashboardApi::viewer())->json('data') ?? [], 'name');
        $found = [$names('search=LOGIN'), $names('search=SHOP'), $names('search=%25'), $names('search=_'), $names('search=%5C'), $names('search=!')];
    } finally {
        // After the API's requests, migrate:rollback finds no batch: drop the tables by hand.
        $schema = Schema::connection("search-{$driver}");
        $schema->disableForeignKeyConstraints();

        foreach (RuleSchema::tables() as $table) {
            $schema->dropIfExists($table);
        }

        RuleSchema::unlock();
    }

    expect($found)->toBe([['Login'], ['Catalog'], ['100% off'], ['Snakes'], ['Back\\slash'], []]);
})->with([
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);
