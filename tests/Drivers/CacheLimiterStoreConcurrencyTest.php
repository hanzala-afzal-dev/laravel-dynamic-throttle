<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Symfony\Component\Process\Process;

/*
| SPEC-002 AC-15 and ADR-0016: many processes hit one counter at once. The cache
| driver may reject a little early at the boundary (in-flight increments of
| requests that end up rejected), but it must never allow more than max.
*/

function concurrencyCache(string $backend, string $host, int $port): Repository
{
    return match ($backend) {
        'memcached' => new Repository(new MemcachedStore(tap(new Memcached, fn (Memcached $memcached) => $memcached->addServer($host, $port)))),
        'redis' => new Repository(new RedisStore(new RedisManager(new Container, 'phpredis', [
            'default' => ['host' => $host, 'port' => $port, 'database' => 0],
        ]))),
    };
}

dataset('shared cache stores', fn (): array => [
    'Memcached' => ['memcached', (string) env('MEMCACHED_HOST', '127.0.0.1'), (int) env('MEMCACHED_PORT', 11211), 'memcached'],
    'Redis cache store' => ['redis', (string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 'redis'],
]);

it('AC-15: never allows more than the limit with 50 concurrent processes', function (string $backend, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    $prefix = 'dt-concurrency-'.bin2hex(random_bytes(4)).':';
    $startAt = microtime(true) + 3.0;
    $processes = [];

    foreach (range(1, 50) as $ignored) {
        $process = new Process([
            PHP_BINARY, __DIR__.'/../Support/concurrency-worker.php',
            $backend, $host, (string) $port, $prefix,
            '20', '100', '60', (string) (FakeClock::T0 + 10), (string) $startAt,
        ]);
        $process->start();
        $processes[] = $process;
    }

    $allowed = 0;

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $allowed += (int) $process->getOutput();
    }

    $windowKey = (new KeyBuilder($prefix))->counter(new CounterKey('1', hash('sha256', 'concurrency')), 60, 30_000_000);

    expect($allowed)->toBeLessThanOrEqual(100)
        ->and($allowed)->toBeGreaterThanOrEqual(50)
        ->and((int) concurrencyCache($backend, $host, $port)->get($windowKey))->toBe($allowed);
})->with('shared cache stores');
