<?php

declare(strict_types=1);

/*
| Worker for the concurrency tests (ADR-0016). It waits until a shared start
| time, so every worker hits the counter at the same moment, makes N attempts
| on one counter, and prints how many were allowed.
|
| php concurrency-worker.php <backend> <host> <port> <prefix> <attempts> <max> <window> <now> <start-at> [release]
|
| With "release", it releases every attempt it was allowed right after it (SPEC-004
| AC-13), and prints how many were allowed and how many were released.
*/

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

require __DIR__.'/../../vendor/autoload.php';

[, $backend, $host, $port, $prefix, $attempts, $max, $window, $now, $startAt] = $argv;
$release = ($argv[10] ?? '') === 'release';

$clock = new FakeClock;
$clock->setSeconds((int) $now);
$redis = fn (): RedisManager => new RedisManager(new Container, 'phpredis', [
    'default' => ['host' => $host, 'port' => (int) $port, 'database' => 0],
]);

$store = match ($backend) {
    'memcached' => new CacheLimiterStore(
        new Repository(new MemcachedStore(tap(new Memcached, fn (Memcached $memcached): bool => $memcached->addServer($host, (int) $port)))),
        new KeyBuilder($prefix),
        $clock,
    ),
    'redis' => new CacheLimiterStore(new Repository(new RedisStore($redis())), new KeyBuilder($prefix), $clock),
    'redis-driver' => new RedisLimiterStore($redis(), 'default', new KeyBuilder($prefix), $clock),
    default => throw new InvalidArgumentException("Unknown backend {$backend}."),
};
$key = new CounterKey('1', hash('sha256', 'concurrency'));

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$allowed = 0;
$released = 0;

for ($i = 0; $i < (int) $attempts; $i++) {
    $attempt = $store->attempt($key, (int) $max, (int) $window);

    if (! $attempt->allowed) {
        continue;
    }

    $allowed++;

    if ($release) {
        $store->release($attempt);
        $released++;
    }
}

echo $release ? "{$allowed} {$released}" : $allowed;
