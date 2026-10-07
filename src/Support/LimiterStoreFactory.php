<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Engine\Telemetry\CacheTelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;

/**
 * Picks and builds the counter driver from config, without network calls (ADR-0005,
 * SPEC-003), and the telemetry buffer on the same driver and store (SPEC-015).
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

    /**
     * @throws InvalidArgumentException for an unknown driver, an undefined store or an invalid prefix
     */
    public function make(): LimiterStore
    {
        [$driver, $target] = $this->pick();

        return $driver === 'redis' ? $this->redisStore((string) $target) : $this->cacheStore($target);
    }

    /**
     * The telemetry buffer, in the store the counters use (ADR-0013 point 2).
     *
     * @throws InvalidArgumentException for an unknown driver, an undefined store or an invalid prefix
     */
    public function telemetry(): TelemetryBuffer
    {
        [$driver, $target] = $this->pick();

        return $driver === 'redis'
            ? new RedisTelemetryBuffer($this->redis, (string) $target, $this->keys)
            : new CacheTelemetryBuffer($this->cache->store($target), $this->keys);
    }

    /**
     * The driver make() picks, and where it counts, for dynamic-throttle:status
     * (SPEC-013): "cache, store array" or "redis, connection default". It builds the
     * store too, so a config mistake throws here as it would on a request.
     *
     * @throws InvalidArgumentException for an unknown driver, an undefined store or an invalid prefix
     */
    public function describe(): string
    {
        $this->make();
        [$driver, $target] = $this->pick();

        // Built offline, a Redis store with no such connection would fail on every request, which then goes through unlimited (the review).
        if ($driver === 'redis' && ! $this->redisConnectionExists((string) $target)) {
            throw new InvalidArgumentException("Redis connection [{$target}] not configured.");
        }

        return $driver === 'redis' ? "redis, connection {$target}" : 'cache, store '.($target ?? 'default');
    }

    /**
     * The driver make() picks, and its cache store, null for the default one, or its
     * Redis connection, from the config alone, for dynamic-throttle:health (SPEC-016).
     *
     * @return array{'cache', ?string}|array{'redis', string}
     *
     * @throws InvalidArgumentException for an unknown driver
     */
    public function driver(): array
    {
        return $this->pick();
    }

    /**
     * As Laravel's RedisManager finds a connection: under database.redis, or among its clusters.
     */
    private function redisConnectionExists(string $name): bool
    {
        return ! in_array($name, ['client', 'options', 'clusters'], true)
            && (is_array($this->config->get("database.redis.{$name}")) || is_array($this->config->get("database.redis.clusters.{$name}")));
    }

    /**
     * @return array{'cache', ?string}|array{'redis', string} the driver, and its cache store or Redis connection
     *
     * @throws InvalidArgumentException for an unknown driver
     */
    private function pick(): array
    {
        $driver = $this->string('dynamic-throttle.driver') ?? 'auto';
        $store = $this->string('dynamic-throttle.store') ?? $this->string('cache.default');
        $storeIsRedis = $this->string("cache.stores.{$store}.driver") === 'redis';
        $storeConnection = $storeIsRedis ? ($this->string("cache.stores.{$store}.connection") ?? 'default') : null;
        // A dedicated connection with short timeouts, whichever mode picks the Redis driver (ADR-0010).
        $redisConnection = $this->string('dynamic-throttle.redis_connection');

        return match ($driver) {
            'auto' => $storeConnection !== null ? ['redis', $redisConnection ?? $storeConnection] : ['cache', $store],
            'cache' => ['cache', $store],
            'redis' => ['redis', $redisConnection ?? $storeConnection ?? 'default'],
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
