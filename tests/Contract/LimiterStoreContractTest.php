<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\StoreUnderTest;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

covers(CacheLimiterStore::class, RedisLimiterStore::class);

/*
|--------------------------------------------------------------------------
| SPEC-002 contract suite: every LimiterStore must pass it
|--------------------------------------------------------------------------
|
| ADR-0005 and ADR-0016. Local stores always run. Service stores run when
| DYNAMIC_THROTTLE_DRIVERS=1, which `composer test:drivers` sets (start the
| services first with `docker compose up -d`). Window 60 s and max 5 unless
| a test says otherwise; times are offsets from FakeClock::T0.
|
*/

function contractCacheStore(CacheRepository $cache): StoreUnderTest
{
    $clock = new FakeClock;

    // A unique prefix per test, so runs against shared services never see each other's counters.
    $keys = new KeyBuilder('dt-test-'.bin2hex(random_bytes(4)).':');

    return new StoreUnderTest(new CacheLimiterStore($cache, $keys, $clock), $clock);
}

function contractCacheTable(string $connection): void
{
    if (Schema::connection($connection)->hasTable('cache')) {
        return;
    }

    Schema::connection($connection)->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration')->index();
    });
}

function contractRedisCache(string $client, string $host, int $port): Repository
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0],
    ]);

    return new Repository(new RedisStore($redis));
}

function contractRedisDriver(string $client, string $host, int $port): StoreUnderTest
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $clock = new FakeClock;
    $redis = new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0],
    ]);
    $keys = new KeyBuilder('dt-test-'.bin2hex(random_bytes(4)).':');

    return new StoreUnderTest(new RedisLimiterStore($redis, 'default', $keys, $clock), $clock);
}

function contractDatabaseCache(string $driver, string $host, int $port): CacheRepository
{
    config()->set("database.connections.dt-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => '',
    ]);
    contractCacheTable("dt-{$driver}");

    return Cache::build(['driver' => 'database', 'connection' => "dt-{$driver}", 'table' => 'cache']);
}

dataset('limiter stores', function (): iterable {
    yield 'cache driver, array store' => [fn (): StoreUnderTest => contractCacheStore(Cache::store('array'))];

    yield 'cache driver, file store' => [fn (): StoreUnderTest => contractCacheStore(Cache::build([
        'driver' => 'file',
        'path' => sys_get_temp_dir().'/dynamic-throttle-'.bin2hex(random_bytes(4)),
    ]))];

    yield 'cache driver, database store (SQLite)' => [function (): StoreUnderTest {
        contractCacheTable('testing');

        return contractCacheStore(Cache::build(['driver' => 'database', 'connection' => 'testing', 'table' => 'cache']));
    }];

    if (getenv('DYNAMIC_THROTTLE_DRIVERS') !== '1') {
        return;
    }

    yield 'cache driver, Memcached' => [function (): StoreUnderTest {
        if (! extension_loaded('memcached')) {
            test()->markTestSkipped('ext-memcached is not installed.');
        }

        return contractCacheStore(Cache::build([
            'driver' => 'memcached',
            'servers' => [['host' => (string) env('MEMCACHED_HOST', '127.0.0.1'), 'port' => (int) env('MEMCACHED_PORT', 11211), 'weight' => 100]],
        ]));
    }];

    foreach (['phpredis', 'predis'] as $client) {
        yield "cache driver, Redis store ({$client})" => [fn (): StoreUnderTest => contractCacheStore(
            contractRedisCache($client, (string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)),
        )];
        yield "cache driver, Valkey store ({$client})" => [fn (): StoreUnderTest => contractCacheStore(
            contractRedisCache($client, (string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)),
        )];
        yield "Redis driver, Redis ({$client})" => [fn (): StoreUnderTest => contractRedisDriver(
            $client, (string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379),
        )];
        yield "Redis driver, Valkey ({$client})" => [fn (): StoreUnderTest => contractRedisDriver(
            $client, (string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380),
        )];
    }

    yield 'cache driver, database store (MySQL)' => [fn (): StoreUnderTest => contractCacheStore(
        contractDatabaseCache('mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307)),
    )];
    yield 'cache driver, database store (PostgreSQL)' => [fn (): StoreUnderTest => contractCacheStore(
        contractDatabaseCache('pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433)),
    )];
});

function contractKey(string $rule = '1', string $identity = 'A'): CounterKey
{
    return new CounterKey($rule, hash('sha256', $identity));
}

/**
 * @return list<array{bool, int}>
 */
function contractAttempts(StoreUnderTest $subject, int $times, int $max = 5, ?CounterKey $key = null): array
{
    $results = [];

    foreach (range(1, $times) as $ignored) {
        $attempt = $subject->store->attempt($key ?? contractKey(), $max, 60);
        $results[] = [$attempt->allowed, $attempt->remaining];
    }

    return $results;
}

it('AC-1: allows the first attempt on a fresh counter', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    $key = contractKey();

    $attempt = $subject->store->attempt($key, 5, 60);

    expect($attempt->allowed)->toBeTrue()
        ->and($attempt->remaining)->toBe(4)
        ->and($attempt->retryAfter)->toBe(0)
        ->and($attempt->limit)->toBe(5)
        ->and($attempt->windowSeconds)->toBe(60)
        ->and($attempt->windowIndex)->toBe(30_000_000)
        ->and($attempt->key)->toBe($key);
})->with('limiter stores');

it('AC-2: allows up to the limit, then rejects with the exact wait', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);

    $allowed = contractAttempts($subject, 5);
    $sixth = $subject->store->attempt(contractKey(), 5, 60);

    expect($allowed)->toBe([[true, 4], [true, 3], [true, 2], [true, 1], [true, 0]])
        ->and($sixth->allowed)->toBeFalse()
        ->and($sixth->remaining)->toBe(0)
        ->and($sixth->retryAfter)->toBe(62);
})->with('limiter stores');

it('AC-3: does not count rejected attempts', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    contractAttempts($subject, 8);

    $subject->clock->setSeconds(FakeClock::T0 + 72);

    expect($subject->store->attempt(contractKey(), 5, 60)->allowed)->toBeTrue();
})->with('limiter stores');

it('AC-4: counts the previous window in proportion to its overlap', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    $first = contractAttempts($subject, 10, max: 10);

    $subject->clock->setSeconds(FakeClock::T0 + 60);
    $atBoundary = $subject->store->attempt(contractKey(), 10, 60);

    $subject->clock->setSeconds(FakeClock::T0 + 90);
    $halfway = array_column(contractAttempts($subject, 6, max: 10), 0);

    expect(array_column($first, 0))->toBe(array_fill(0, 10, true))
        ->and($atBoundary->allowed)->toBeFalse()
        ->and($halfway)->toBe([true, true, true, true, true, false]);
})->with('limiter stores');

it('AC-5: forgets everything two windows later', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    contractAttempts($subject, 5);

    $subject->clock->setSeconds(FakeClock::T0 + 120);
    $attempt = $subject->store->attempt(contractKey(), 5, 60);

    expect($attempt->allowed)->toBeTrue()
        ->and($attempt->remaining)->toBe(4);
})->with('limiter stores');

it('AC-6: keeps counters apart by rule, identity and window length', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    contractAttempts($subject, 5);

    $otherIdentity = $subject->store->attempt(contractKey('1', 'B'), 5, 60);
    $otherRule = $subject->store->attempt(contractKey('2', 'A'), 5, 60);
    $otherWindow = $subject->store->attempt(contractKey('1', 'A'), 5, 30);

    expect([$otherIdentity->allowed, $otherIdentity->remaining])->toBe([true, 4])
        ->and([$otherRule->allowed, $otherRule->remaining])->toBe([true, 4])
        ->and([$otherWindow->allowed, $otherWindow->remaining])->toBe([true, 4]);
})->with('limiter stores');

it('AC-7: applies a lower limit at once, because counters are kept', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);
    contractAttempts($subject, 3);

    expect($subject->store->attempt(contractKey(), 3, 60)->allowed)->toBeFalse();
})->with('limiter stores');

it('AC-16: rejects an invalid limit or window before counting anything', function (StoreUnderTest $subject, int $max, int $window): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);

    expect(fn (): Attempt => $subject->store->attempt(contractKey(), $max, $window))->toThrow(InvalidArgumentException::class);

    $next = $subject->store->attempt(contractKey(), 5, 60);

    expect([$next->allowed, $next->remaining])->toBe([true, 4]);
})->with('limiter stores')->with([
    'max 0' => [0, 60],
    'window 0' => [5, 0],
]);

it('AC-16: accepts the smallest valid limit and window', function (StoreUnderTest $subject): void {
    $subject->clock->setSeconds(FakeClock::T0 + 10);

    $first = $subject->store->attempt(contractKey(), 1, 1);
    $second = $subject->store->attempt(contractKey(), 1, 1);

    expect([$first->allowed, $first->remaining])->toBe([true, 0])
        ->and([$second->allowed, $second->retryAfter])->toBe([false, 2]);
})->with('limiter stores');

it('AC-8: puts window edges exactly on the microsecond', function (StoreUnderTest $subject): void {
    $subject->clock->setMicroseconds((FakeClock::T0 + 59) * 1_000_000 + 999_999);
    $lastMicrosecond = $subject->store->attempt(contractKey(), 5, 60);
    contractAttempts($subject, 4);

    $subject->clock->setSeconds(FakeClock::T0 + 60);
    $nextWindow = $subject->store->attempt(contractKey(), 5, 60);

    expect($lastMicrosecond->windowIndex)->toBe(30_000_000)
        ->and($nextWindow->windowIndex)->toBe(30_000_001)
        ->and($nextWindow->allowed)->toBeFalse();
})->with('limiter stores');
