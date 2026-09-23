<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
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

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
});
