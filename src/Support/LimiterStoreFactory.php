<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;

/**
 * Picks and builds the counter driver from config, without network calls (ADR-0005, SPEC-003).
 */
final readonly class LimiterStoreFactory
{
    public function __construct(
        private Config $config,
        private CacheFactory $cache,
        private RedisFactory $redis,
        private KeyBuilder $keys,
        private Clock $clock,
    ) {}

    public function make(): LimiterStore
    {
        $driver = $this->string('dynamic-throttle.driver') ?? 'auto';
        $store = $this->string('dynamic-throttle.store') ?? $this->string('cache.default');
        $storeIsRedis = $this->string("cache.stores.{$store}.driver") === 'redis';
        $storeConnection = $storeIsRedis ? ($this->string("cache.stores.{$store}.connection") ?? 'default') : null;

        return match ($driver) {
            'auto' => $storeConnection !== null ? $this->redisStore($storeConnection) : $this->cacheStore($store),
            'cache' => $this->cacheStore($store),
            'redis' => $this->redisStore($this->string('dynamic-throttle.redis_connection') ?? $storeConnection ?? 'default'),
            default => throw new InvalidArgumentException("Unknown dynamic-throttle.driver \"{$driver}\": use auto, redis or cache."),
        };
    }

    private function cacheStore(?string $store): CacheLimiterStore
    {
        return new CacheLimiterStore($this->cache->store($store), $this->keys, $this->clock);
    }

    private function redisStore(string $connection): RedisLimiterStore
    {
        // The connection is resolved on the first attempt, so building the store is offline.
        return new RedisLimiterStore($this->redis, $connection, $this->keys);
    }

    private function string(string $key): ?string
    {
        $value = $this->config->get($key);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
