<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use InvalidArgumentException;
use Memcached;

/**
 * The docker compose services, with the same environment variables and ports
 * as the driver tests (`docker compose up -d`).
 */
final class Services
{
    /**
     * @param  'redis'|'valkey'  $server
     */
    public static function redis(string $client, string $server = 'redis'): RedisManager
    {
        [$host, $port] = match ($server) {
            'redis' => [self::env('REDIS_HOST', '127.0.0.1'), (int) self::env('REDIS_PORT', '6379')],
            'valkey' => [self::env('VALKEY_HOST', '127.0.0.1'), (int) self::env('VALKEY_PORT', '6380')],
        };

        return new RedisManager(new Container, $client, [
            'default' => ['host' => $host, 'port' => $port, 'database' => 0],
        ]);
    }

    /**
     * The local Redis as a connection in Laravel's database.redis config.
     *
     * @return array{host: string, port: int, database: int}
     */
    public static function redisConnection(): array
    {
        return ['host' => self::env('REDIS_HOST', '127.0.0.1'), 'port' => (int) self::env('REDIS_PORT', '6379'), 'database' => 0];
    }

    /**
     * The local Memcached as the servers of a memcached store in Laravel's cache config.
     *
     * @return list<array{host: string, port: int, weight: int}>
     */
    public static function memcachedServers(): array
    {
        return [['host' => self::env('MEMCACHED_HOST', '127.0.0.1'), 'port' => (int) self::env('MEMCACHED_PORT', '11211'), 'weight' => 100]];
    }

    public static function cache(string $store): Repository
    {
        return match ($store) {
            'array' => new Repository(new ArrayStore),
            'redis' => new Repository(new RedisStore(self::redis('phpredis'))),
            'memcached' => new Repository(new MemcachedStore(self::memcached())),
            default => throw new InvalidArgumentException("Unknown cache store {$store}."),
        };
    }

    public static function prefix(): string
    {
        return 'dt-bench-'.bin2hex(random_bytes(4)).':';
    }

    private static function memcached(): Memcached
    {
        $memcached = new Memcached;
        $memcached->addServer(self::env('MEMCACHED_HOST', '127.0.0.1'), (int) self::env('MEMCACHED_PORT', '11211'));

        return $memcached;
    }

    private static function env(string $name, string $default): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : $default;
    }
}
