<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\CounterReading;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

covers(CacheLimiterStore::class);

/*
| SPEC-002 AC-19 and AC-20: cache-driver behavior that needs a real service.
*/

it('AC-19: counts on a Redis cache store whose phpredis connection serializes values', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new RedisManager(new Container, 'phpredis', [
        'default' => [
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'serializer' => Redis::SERIALIZER_PHP,
        ],
    ]);
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore(new Repository(new RedisStore($redis)), new KeyBuilder('dt-serializer-'.bin2hex(random_bytes(4)).':'), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    $decisions = array_map(fn (): bool => $store->attempt($key, 5, 60)->allowed, range(1, 6));

    expect($decisions)->toBe([true, true, true, true, true, false]);
});

it('AC-20: reports a failed read of the previous window as unavailable, not as 0', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // Counting works (a live server), but reads go to a server that is down,
    // so get() returns null with an error result code.
    $live = new Memcached;
    $live->addServer((string) env('MEMCACHED_HOST', '127.0.0.1'), (int) env('MEMCACHED_PORT', 11211));
    $down = new Memcached;
    $down->addServer('127.0.0.1', 1);

    $cache = new Repository(new class($live, $down) extends MemcachedStore
    {
        public function __construct(Memcached $live, private readonly Memcached $down)
        {
            parent::__construct($live);
        }

        public function get($key): mixed
        {
            return (new MemcachedStore($this->down))->get($key);
        }

        public function getMemcached(): Memcached
        {
            return $this->down;
        }
    });
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt-memcached-'.bin2hex(random_bytes(4)).':'), $clock);

    // The message names Memcached's own error, such as "SERVER IS MARKED DEAD".
    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not read the previous window: \S/');
        });
});

it('SPEC-014: peek() reports a failed read as unavailable, not as 0', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // Reads go to a server that is down, so get() returns null with an error result code.
    $down = new Memcached;
    $down->addServer('127.0.0.1', 1);
    $store = new CacheLimiterStore(new Repository(new MemcachedStore($down)), new KeyBuilder('dt-memcached-'.bin2hex(random_bytes(4)).':'), new FakeClock);

    // The message names Memcached's own error, such as "SERVER IS MARKED DEAD".
    expect(fn (): CounterReading => $store->peek(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not read the counter: \S/');
        });
});

/*
| SPEC-004 AC-11 and AC-12: release() with the cache driver on real services.
*/

it('SPEC-004 AC-11: releases on a Redis cache store whose phpredis connection serializes values', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new RedisManager(new Container, 'phpredis', [
        'default' => [
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'serializer' => Redis::SERIALIZER_PHP,
        ],
    ]);
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore(new Repository(new RedisStore($redis)), new KeyBuilder('dt-serializer-'.bin2hex(random_bytes(4)).':'), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));
    $attempts = array_map(fn (): Attempt => $store->attempt($key, 5, 60), range(1, 5));

    $store->release($attempts[0]);

    expect($store->attempt($key, 5, 60)->allowed)->toBeTrue();
});

it('SPEC-004 AC-12: reports a failed read of the window as unavailable when releasing', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // Reads go to a server that is down, so get() returns null with an error result code.
    $down = new Memcached;
    $down->addServer('127.0.0.1', 1);
    $store = new CacheLimiterStore(new Repository(new MemcachedStore($down)), new KeyBuilder('dt-memcached-'.bin2hex(random_bytes(4)).':'), new FakeClock);
    $allowed = new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, true, 4, 0);

    // The message names Memcached's own error, such as "SERVER IS MARKED DEAD".
    expect(fn () => $store->release($allowed))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not read the window: \S/');
        });
});

it('SPEC-004 AC-12: reports a failed decrement of the window as unavailable when releasing', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // The read succeeded before the server went down, so decrement() returns false with an error result code.
    $down = new Memcached;
    $down->addServer('127.0.0.1', 1);
    $cache = new Repository(new class($down) extends MemcachedStore
    {
        public function get($key): mixed
        {
            return 3;
        }
    });
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt-memcached-'.bin2hex(random_bytes(4)).':'), new FakeClock);
    $allowed = new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, true, 2, 0);

    // The message names Memcached's own error, such as "SERVER IS MARKED DEAD".
    expect(fn () => $store->release($allowed))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not decrement the window: \S/');
        });
});

it('SPEC-004 AC-12: releases nothing when the window key expires between the read and the decrement on Memcached', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // The read saw a count, but the key is gone by the decrement: false with "NOT FOUND".
    $memcached = new Memcached;
    $memcached->addServer((string) env('MEMCACHED_HOST', '127.0.0.1'), (int) env('MEMCACHED_PORT', 11211));
    $keys = new KeyBuilder('dt-memcached-'.bin2hex(random_bytes(4)).':');
    $cache = new Repository(new class($memcached) extends MemcachedStore
    {
        public function get($key): mixed
        {
            return 3;
        }
    });
    $store = new CacheLimiterStore($cache, $keys, new FakeClock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    $store->release(new Attempt($key, 5, 60, 30_000_000, true, 2, 0));

    expect($memcached->get($keys->counter($key, 60, 30_000_000)))->toBeFalse()
        ->and($memcached->getResultCode())->toBe(Memcached::RES_NOTFOUND);
});
