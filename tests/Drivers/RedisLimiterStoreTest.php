<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

covers(RedisLimiterStore::class);

/*
| SPEC-003: behavior specific to the Redis driver. The shared behavior is in
| tests/Contract/LimiterStoreContractTest.php. Window 60 s, max 5.
*/

function redisDriverManager(string $client, ?int $port = null): RedisManager
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    return new RedisManager(new Container, $client, [
        'default' => [
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => $port ?? (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'read_write_timeout' => 1.0,
        ],
    ]);
}

function redisDriverPrefix(): string
{
    return 'dt-redis-'.bin2hex(random_bytes(4)).':';
}

/**
 * Server statistics through a separate phpredis connection, whichever client is under test.
 */
function redisServer(): Redis
{
    if (! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new Redis;
    $redis->connect((string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379), 1.0);

    return $redis;
}

function redisCommandCalls(string $command): int
{
    $stats = redisServer()->info('commandstats');
    preg_match('/calls=(\d+)/', (string) ($stats["cmdstat_{$command}"] ?? ''), $match);

    return (int) ($match[1] ?? 0);
}

dataset('redis clients', ['phpredis', 'predis']);

it('AC-2: keeps one hash per counter, with a field per window and a TTL of 2 × window + 5', function (string $client): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $redis = redisDriverManager($client);
    $prefix = redisDriverPrefix();
    $store = new RedisLimiterStore($redis, 'default', new KeyBuilder($prefix), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    foreach (range(1, 3) as $ignored) {
        $store->attempt($key, 5, 60);
    }

    $hashKey = "{$prefix}c:1:60:{$key->identityHash}";

    expect($redis->connection()->hgetall($hashKey))->toEqual(['30000000' => '3'])
        ->and($redis->connection()->ttl($hashKey))->toBeIn([124, 125]);
})->with('redis clients');

it('AC-3: removes windows older than the previous one', function (string $client): void {
    $clock = new FakeClock;
    $redis = redisDriverManager($client);
    $prefix = redisDriverPrefix();
    $store = new RedisLimiterStore($redis, 'default', new KeyBuilder($prefix), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    foreach ([10, 70, 130] as $offset) {
        $clock->setSeconds(FakeClock::T0 + $offset);
        $store->attempt($key, 5, 60);
    }

    $fields = array_map(intval(...), $redis->connection()->hkeys("{$prefix}c:1:60:{$key->identityHash}"));
    sort($fields);

    expect($fields)->toBe([30_000_001, 30_000_002]);
})->with('redis clients');

it('AC-4: writes nothing for rejected attempts', function (string $client): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $redis = redisDriverManager($client);
    $prefix = redisDriverPrefix();
    $store = new RedisLimiterStore($redis, 'default', new KeyBuilder($prefix), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    foreach (range(1, 8) as $ignored) {
        $store->attempt($key, 5, 60);
    }

    expect($redis->connection()->hget("{$prefix}c:1:60:{$key->identityHash}", '30000000'))->toEqual(5);
})->with('redis clients');

it('AC-5: takes the time from Redis TIME when there is no test clock', function (string $client): void {
    $redis = redisDriverManager($client);
    $store = new RedisLimiterStore($redis, 'default', new KeyBuilder(redisDriverPrefix()));
    $key = new CounterKey('1', hash('sha256', 'A'));

    $before = (int) $redis->connection()->time()[0];
    $attempt = $store->attempt($key, 5, 60);
    $after = (int) $redis->connection()->time()[0];

    expect($attempt->windowIndex)->toBeIn([intdiv($before, 60), intdiv($after, 60)]);
})->with('redis clients');

it('AC-6: falls back to EVAL after SCRIPT FLUSH, then uses EVALSHA', function (string $client): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new RedisLimiterStore(redisDriverManager($client), 'default', new KeyBuilder(redisDriverPrefix()), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));
    redisServer()->script('flush');

    $afterFlush = $store->attempt($key, 5, 60);
    $loaded = redisServer()->script('exists', RedisLimiterStore::scriptSha());

    $evalsha = redisCommandCalls('evalsha');
    $eval = redisCommandCalls('eval');
    $store->attempt($key, 5, 60);

    expect($afterFlush->allowed)->toBeTrue()
        ->and($loaded)->toBe([1])
        ->and(redisCommandCalls('evalsha') - $evalsha)->toBe(1)
        ->and(redisCommandCalls('eval') - $eval)->toBe(0);
})->with('redis clients');

it('AC-7: reports a Redis it cannot reach as unavailable', function (string $client): void {
    $store = new RedisLimiterStore(redisDriverManager($client, port: 1), 'default', new KeyBuilder('dt:'), new FakeClock);

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
})->with('redis clients');
