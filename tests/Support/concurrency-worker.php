<?php

declare(strict_types=1);

/*
| Worker for the concurrency tests (ADR-0016). It waits until a shared start
| time, so every worker hits the counter at the same moment, makes N attempts
| on one counter, and prints how many were allowed.
|
| php concurrency-worker.php <backend> <host> <port> <prefix> <attempts> <max> <window> <now> <start-at>
*/

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

require __DIR__.'/../../vendor/autoload.php';

[, $backend, $host, $port, $prefix, $attempts, $max, $window, $now, $startAt] = $argv;

$cache = match ($backend) {
    'memcached' => new Repository(new MemcachedStore(tap(new Memcached, fn (Memcached $memcached) => $memcached->addServer($host, (int) $port)))),
    'redis' => new Repository(new RedisStore(new RedisManager(new Container, 'phpredis', [
        'default' => ['host' => $host, 'port' => (int) $port, 'database' => 0],
    ]))),
    default => throw new InvalidArgumentException("Unknown backend {$backend}."),
};

$clock = new FakeClock;
$clock->setSeconds((int) $now);
$store = new CacheLimiterStore($cache, new KeyBuilder($prefix), $clock);
$key = new CounterKey('1', hash('sha256', 'concurrency'));

while (microtime(true) < (float) $startAt) {
    usleep(200);
}

$allowed = 0;

for ($i = 0; $i < (int) $attempts; $i++) {
    $allowed += $store->attempt($key, (int) $max, (int) $window)->allowed ? 1 : 0;
}

echo $allowed;
