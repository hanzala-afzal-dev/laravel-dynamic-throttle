<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\FileStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;

covers(LimiterStoreFactory::class);

/*
| SPEC-003 AC-10 to AC-15: driver selection from config. None of these tests
| needs a running Redis: resolving a store must never touch the network.
*/

beforeEach(function (): void {
    // predis is pure PHP, so these tests also run where ext-redis is missing.
    config()->set('database.redis.client', 'predis');
    config()->set('cache.stores.throttle-redis', ['driver' => 'redis', 'connection' => 'cache']);
});

function selectedStore(): LimiterStore
{
    return app(LimiterStoreFactory::class)->make();
}

it('AC-10: auto picks the Redis driver on a redis cache store, on that store\'s connection', function (): void {
    config()->set('cache.default', 'throttle-redis');

    $store = selectedStore();

    expect($store)->toBeInstanceOf(RedisLimiterStore::class)
        ->and($store->connection)->toBe('cache');
});

it('AC-10: auto uses redis_connection on a redis cache store when it is set', function (): void {
    config()->set('cache.default', 'throttle-redis');
    config()->set('dynamic-throttle.redis_connection', 'limiter');

    $store = selectedStore();

    expect($store)->toBeInstanceOf(RedisLimiterStore::class)
        ->and($store->connection)->toBe('limiter');
});

it('AC-10: auto picks the cache driver on any other store', function (string $name, string $storeClass): void {
    if ($name === 'memcached' && ! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    config()->set('cache.default', $name);

    $store = selectedStore();

    expect($store)->toBeInstanceOf(CacheLimiterStore::class)
        ->and($store->cache->getStore())->toBeInstanceOf($storeClass);
})->with([
    'array' => ['array', ArrayStore::class],
    'file' => ['file', FileStore::class],
    'database' => ['database', DatabaseStore::class],
    'memcached' => ['memcached', MemcachedStore::class],
]);

it('AC-11: driver cache uses the cache driver even on a redis store', function (): void {
    config()->set('cache.default', 'throttle-redis');
    config()->set('dynamic-throttle.driver', 'cache');

    $store = selectedStore();

    expect($store)->toBeInstanceOf(CacheLimiterStore::class)
        ->and($store->cache->getStore())->toBeInstanceOf(RedisStore::class);
});

it('AC-11: driver redis uses redis_connection, else the store\'s connection, else default', function (?string $redisConnection, string $defaultStore, string $expected): void {
    config()->set('cache.default', $defaultStore);
    config()->set('dynamic-throttle.driver', 'redis');
    config()->set('dynamic-throttle.redis_connection', $redisConnection);

    $store = selectedStore();

    expect($store)->toBeInstanceOf(RedisLimiterStore::class)
        ->and($store->connection)->toBe($expected);
})->with([
    'redis_connection set' => ['limiter', 'throttle-redis', 'limiter'],
    'the store\'s connection' => [null, 'throttle-redis', 'cache'],
    'default otherwise' => [null, 'array', 'default'],
]);

it('AC-12: the store setting overrides the default cache store', function (): void {
    config()->set('cache.default', 'array');
    config()->set('dynamic-throttle.store', 'file');

    expect(selectedStore()->cache->getStore())->toBeInstanceOf(FileStore::class);
});

it('AC-13: resolves the store from the container without touching the network', function (string $client): void {
    // predis connects lazily anyway; phpredis would fail at once if resolving connected.
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.unreachable', ['host' => '127.0.0.1', 'port' => 1, 'timeout' => 0.5, 'read_write_timeout' => 0.5]);
    config()->set('cache.stores.throttle-redis.connection', 'unreachable');
    config()->set('cache.default', 'throttle-redis');

    $store = app(LimiterStore::class);

    expect($store)->toBeInstanceOf(RedisLimiterStore::class)
        ->and(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
})->with(['phpredis', 'predis']);

it('AC-14: rejects an unknown driver and names the valid ones', function (): void {
    config()->set('dynamic-throttle.driver', 'memcached');

    expect(fn (): LimiterStore => selectedStore())
        ->toThrow(InvalidArgumentException::class, 'auto, redis or cache');
});

it('AC-15: builds keys with the configured prefix, dt: by default', function (): void {
    $default = app(KeyBuilder::class)->prefix;

    config()->set('dynamic-throttle.prefix', 'myapp:');

    expect($default)->toBe('dt:')
        ->and(app(KeyBuilder::class)->prefix)->toBe('myapp:');
});
