<?php

declare(strict_types=1);

use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| Phase 0 smoke tests for the driver suite (ADR-0016)
|--------------------------------------------------------------------------
|
| They prove that the services from docker-compose.yml, and the CI service
| containers, are reachable with every client the driver contract suite will
| use. SPEC-002 and SPEC-003 replace them with the shared contract suite.
|
| Run with `composer test:drivers` after `docker compose up -d`. An unreachable
| service fails the test instead of skipping it, so a broken setup is loud.
| Only a missing PHP extension skips.
|
*/

dataset('redis-compatible servers', function (): iterable {
    $servers = [
        'Redis' => [(string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)],
        'Valkey' => [(string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)],
    ];

    foreach (['phpredis', 'predis'] as $client) {
        foreach ($servers as $server => [$host, $port]) {
            yield "{$server} via {$client}" => [$client, $host, $port];
        }
    }
});

dataset('database servers', fn (): array => [
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);

it('reaches Redis-compatible servers and runs Lua with TIME', function (string $client, string $host, int $port): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new RedisManager($this->app, $client, [
        'options' => ['prefix' => 'dynamic-throttle-smoke:'],
        'default' => [
            'host' => $host,
            'port' => $port,
            'database' => 0,
            'timeout' => 2.0,
            'read_timeout' => 2.0,
            'read_write_timeout' => 2.0,
        ],
    ]);
    $connection = $redis->connection();

    $connection->set('ping', 'pong', 'EX', 10);
    expect($connection->get('ping'))->toBe('pong');

    // The redis driver reads the clock with TIME inside a Lua script (ADR-0006).
    expect($connection->eval("return redis.call('TIME')", 0))->toBeArray()->toHaveCount(2);

    $connection->del('ping');
})->with('redis-compatible servers');

it('reaches Memcached through the cache repository', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    config()->set('cache.stores.smoke-memcached', [
        'driver' => 'memcached',
        'servers' => [[
            'host' => (string) env('MEMCACHED_HOST', '127.0.0.1'),
            'port' => (int) env('MEMCACHED_PORT', 11211),
            'weight' => 100,
        ]],
    ]);
    $store = Cache::store('smoke-memcached');
    $key = 'dynamic-throttle-smoke:'.bin2hex(random_bytes(4));

    // The cache driver relies on atomic add and increment (ADR-0005).
    expect($store->add($key, 1, 10))->toBeTrue()
        ->and($store->increment($key))->toBe(2);

    $store->forget($key);
});

it('reaches database servers', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.smoke-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => '',
    ]);

    expect(DB::connection("smoke-{$driver}")->selectOne('select 1 as one')->one)->toEqual(1);
})->with('database servers');
