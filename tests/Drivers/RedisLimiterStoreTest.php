<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;

covers(RedisLimiterStore::class);

/*
| SPEC-003: behavior specific to the Redis driver. The shared behavior is in
| tests/Contract/LimiterStoreContractTest.php. Window 60 s, max 5.
*/

/**
 * @param  array<string, mixed>  $options  Laravel's options for every connection, such as a key prefix.
 */
function redisDriverManager(string $client, ?int $port = null, ?string $username = null, array $options = []): RedisManager
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    return new RedisManager(new Container, $client, [
        'options' => $options,
        'default' => array_filter([
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => $port ?? (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'timeout' => 1.0,
            'read_timeout' => 1.0,
            'read_write_timeout' => 1.0,
            // The ACL test user is created with "nopass", which accepts any password.
            'username' => $username,
            'password' => $username !== null ? 'any' : null,
        ], fn (mixed $value): bool => $value !== null),
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

/**
 * Starts tests/Support/tcp-proxy.php in front of Redis, on a free port unless one is given.
 *
 * @return array{Process, int} the proxy and its port
 */
function redisProxy(int $port = 0): array
{
    $proxy = new Process([
        PHP_BINARY, __DIR__.'/../Support/tcp-proxy.php',
        (string) $port, (string) env('REDIS_HOST', '127.0.0.1'), (string) env('REDIS_PORT', 6379),
    ]);
    $proxy->start();
    $proxy->waitUntil(fn (string $type, string $output): bool => $type === Process::OUT && str_contains($output, "\n"));

    $listening = (int) trim($proxy->getOutput());
    expect($listening)->toBeGreaterThan(0, 'The proxy did not start: '.$proxy->getErrorOutput());

    return [$proxy, $listening];
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

    // In milliseconds, so a TTL of 2 × window + 4 = 124 s fails.
    expect($redis->connection()->hgetall($hashKey))->toEqual(['30000000' => '3'])
        ->and((int) $redis->connection()->pttl($hashKey))->toBeGreaterThan(124_000)->toBeLessThanOrEqual(125_000);
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

it('AC-5: the container gives the Redis driver no clock, so time comes from TIME', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.limiter-time', ['host' => (string) env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 0]);
    config()->set('cache.stores.throttle-redis', ['driver' => 'redis', 'connection' => 'limiter-time']);
    config()->set('cache.default', 'throttle-redis');
    config()->set('dynamic-throttle.prefix', redisDriverPrefix());
    // The application clock says 2030; Redis TIME says now.
    $this->travelTo(Carbon::create(2030));

    $store = app(LimiterStore::class);
    $before = (int) redisServer()->time()[0];
    $attempt = $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60);
    $after = (int) redisServer()->time()[0];

    expect($store)->toBeInstanceOf(RedisLimiterStore::class)
        ->and($attempt->windowIndex)->toBeIn([intdiv($before, 60), intdiv($after, 60)]);
})->with('redis clients');

it('AC-6: falls back to EVAL after SCRIPT FLUSH, then uses EVALSHA', function (string $client): void {
    $server = redisServer();
    // A user that may not run EVAL: its attempts only succeed through EVALSHA.
    $server->rawCommand('ACL', 'SETUSER', 'dt-evalsha-only', 'reset', 'on', 'nopass', '~*', '&*', '+@all', '-eval');

    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $keys = new KeyBuilder(redisDriverPrefix());
    $store = new RedisLimiterStore(redisDriverManager($client), 'default', $keys, $clock);
    $evalshaOnly = new RedisLimiterStore(redisDriverManager($client, username: 'dt-evalsha-only'), 'default', $keys, $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    // This test flushes the script cache. The lock keeps its two dataset rows
    // apart under --parallel; other tests just fall back to EVAL after a flush.
    while (! $server->set('dt-test:script-flush-lock', '1', ['nx', 'ex' => 30])) {
        usleep(10_000);
    }

    try {
        $server->script('flush');
        $afterFlush = $store->attempt($key, 5, 60);
        $loaded = $server->script('exists', RedisLimiterStore::scriptSha());
        $throughEvalsha = $evalshaOnly->attempt($key, 5, 60);
    } finally {
        $server->del('dt-test:script-flush-lock');
    }

    expect($afterFlush->allowed)->toBeTrue()
        ->and($loaded)->toBe([1])
        ->and([$throughEvalsha->allowed, $throughEvalsha->remaining])->toBe([true, 3]);
})->with('redis clients');

it('AC-7: reports a Redis it cannot reach as unavailable', function (string $client): void {
    $store = new RedisLimiterStore(redisDriverManager($client, port: 1), 'default', new KeyBuilder('dt:'), new FakeClock);

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
})->with('redis clients');

it('AC-16: works again after Redis was unreachable for a while', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    // One store for the whole test, like an Octane worker. Stopping the proxy is the outage.
    [$proxy, $port] = redisProxy();
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new RedisLimiterStore(new RedisManager(new Container, $client, [
        'default' => ['host' => '127.0.0.1', 'port' => $port, 'database' => 0, 'timeout' => 1.0, 'read_timeout' => 1.0, 'read_write_timeout' => 1.0],
    ]), 'default', new KeyBuilder(redisDriverPrefix()), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    $outcome = function () use ($store, $key): bool|string {
        try {
            return $store->attempt($key, 100, 60)->allowed;
        } catch (StorageUnavailableException) {
            return 'unavailable';
        }
    };

    try {
        $before = $outcome();
        $proxy->stop(0);
        $duringOutage = $outcome();
        [$proxy] = redisProxy($port);
        $afterOutage = [$outcome(), $outcome()];
    } finally {
        $proxy->stop(0);
    }

    // Laravel rebuilds a phpredis client on the command after a lost connection,
    // so the first attempt after the outage may still fail.
    expect($before)->toBeTrue()
        ->and($duringOutage)->toBe('unavailable')
        ->and($afterOutage)->toContain(true);
})->with('redis clients');

dataset('redis error modes', [
    'phpredis' => ['phpredis', []],
    'predis' => ['predis', []],
    'predis with exceptions off' => ['predis', ['exceptions' => false]],
]);

it('AC-17: reports an error reply from Redis as unavailable, naming the error', function (string $client, array $options): void {
    $redis = redisDriverManager($client, options: $options);
    $keys = new KeyBuilder(redisDriverPrefix());
    $key = new CounterKey('1', hash('sha256', 'A'));
    // The counter's key holds a string, so the script's HGET fails with WRONGTYPE.
    $redis->connection()->set($keys->counterBase($key, 60), 'x', 'EX', 60);
    $store = new RedisLimiterStore($redis, 'default', $keys, new FakeClock);

    expect(fn (): Attempt => $store->attempt($key, 5, 60))
        ->toThrow(function (StorageUnavailableException $exception): void {
            $messages = [];

            for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
                $messages[] = $current->getMessage();
            }

            expect(implode(' <- ', $messages))->toContain('WRONGTYPE');
        });
})->with('redis error modes');

it('AC-19: applies Laravel\'s Redis key prefix to the script\'s key', function (string $client): void {
    $laravelPrefix = 'dt-app-'.bin2hex(random_bytes(4)).':';
    $keys = new KeyBuilder(redisDriverPrefix());
    $key = new CounterKey('1', hash('sha256', 'A'));
    $store = new RedisLimiterStore(redisDriverManager($client, options: ['prefix' => $laravelPrefix]), 'default', $keys, new FakeClock);

    $store->attempt($key, 5, 60);

    $server = redisServer();
    $base = $keys->counterBase($key, 60);

    expect([(int) $server->exists($laravelPrefix.$base), (int) $server->exists($base)])->toBe([1, 0]);
})->with('redis clients');
