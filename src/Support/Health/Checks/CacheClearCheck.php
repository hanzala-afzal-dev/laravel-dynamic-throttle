<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Support\ConfigurationUrlParser;
use InvalidArgumentException;

/**
 * Whether php artisan cache:clear wipes the counters (SPEC-016 AC-6, ADR-0020
 * point 2): it flushes a redis cache store's whole Redis database, and the Redis
 * driver shares the `cache` connection's by default. The cache driver keeps its
 * counters in a cache store by design, as Laravel's throttle does (decision 9).
 *
 * @internal
 */
final readonly class CacheClearCheck implements Check
{
    public function id(): string
    {
        return 'cache-clear';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->driver() instanceof InvalidArgumentException) {
            return CheckResult::skipped($this->id(), 'The config is invalid: see the config check.');
        }

        [$driver, $connection] = $context->container->make(LimiterStoreFactory::class)->driver();

        if ($driver !== 'redis') {
            return CheckResult::ok($this->id(), 'The cache driver keeps the counters in a cache store by design, as Laravel\'s throttle does, so clearing that store resets them.');
        }

        $stores = $this->sharing($context->config, (string) $connection);

        return $stores === []
            ? CheckResult::ok($this->id(), 'No cache store uses the Redis database the counters are in, so cache:clear leaves them.')
            : CheckResult::warning($this->id(), 'The counters are in the Redis database of the '.HealthContext::listed($stores).' cache '.(count($stores) === 1 ? 'store' : 'stores')
                .', which php artisan cache:clear flushes, every limit with it. Give the Redis driver a dedicated connection on a database of its own, in dynamic-throttle.redis_connection.');
    }

    /**
     * The redis cache stores whose Redis database the counters' connection uses: the
     * same connection, or another one to the same host, port and database.
     *
     * @return list<string>
     */
    private function sharing(Config $config, string $connection): array
    {
        $counters = $this->database($config, $connection);
        $stores = (array) $config->get('cache.stores');
        $sharing = [];

        foreach ($stores as $name => $store) {
            if (! is_array($store) || ($store['driver'] ?? null) !== 'redis') {
                continue;
            }

            $storeConnection = is_string($store['connection'] ?? null) ? $store['connection'] : 'default';

            if ($storeConnection === $connection || ($counters !== null && $counters === $this->database($config, $storeConnection))) {
                $sharing[] = "\"{$name}\"";
            }
        }

        return $sharing;
    }

    /**
     * Where a connection's keys live: its host, port and database, its url included,
     * as Laravel's RedisManager reads them. Null for a cluster, or a connection the
     * config lacks.
     *
     * @return array{string, int, int}|null
     */
    private function database(Config $config, string $name): ?array
    {
        $settings = $config->get("database.redis.{$name}");

        if (in_array($name, ['client', 'options', 'clusters'], true) || ! is_array($settings)) {
            return null;
        }

        $named = [];

        foreach ($settings as $key => $value) {
            if (is_string($key)) {
                $named[$key] = $value;
            }
        }

        $parsed = (new ConfigurationUrlParser)->parseConfiguration($named);
        $host = $parsed['host'] ?? '127.0.0.1';
        $port = $parsed['port'] ?? 6379;
        $database = $parsed['database'] ?? 0;

        return [
            strtolower(is_string($host) ? $host : '127.0.0.1'),
            is_numeric($port) ? (int) $port : 6379,
            is_numeric($database) ? (int) $database : 0,
        ];
    }
}
