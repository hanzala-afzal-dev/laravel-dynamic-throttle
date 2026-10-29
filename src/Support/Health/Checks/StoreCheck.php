<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use InvalidArgumentException;

/**
 * What the counter store guarantees (SPEC-016 AC-5, ADR-0005 point 4), with
 * SPEC-002's measurements of the stores that are not safe under concurrency.
 * A store of another driver is ok, with a note (decision 15).
 *
 * @internal
 */
final readonly class StoreCheck implements Check
{
    private const string ADVICE = ' Prefer Redis, Valkey or Memcached.';

    public function id(): string
    {
        return 'store';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->driver() instanceof InvalidArgumentException) {
            return CheckResult::skipped($this->id(), 'The config is invalid: see the config check.');
        }

        [$driver, $target] = $context->container->make(LimiterStoreFactory::class)->driver();

        if ($driver === 'redis') {
            return CheckResult::ok($this->id(), "The Redis driver counts on the Redis connection \"{$target}\", which every server shares, in one script call per request.");
        }

        $store = $target ?? '';
        $type = $context->config->get("cache.stores.{$store}.driver");
        $type = is_string($type) ? $type : '';
        $counts = "The cache driver counts in the \"{$store}\" store, a {$type} store";

        return match ($type) {
            'redis', 'memcached', 'dynamodb' => CheckResult::ok($this->id(), "{$counts}, which every server shares."),
            'database' => CheckResult::warning($this->id(), "{$counts}, which adds database writes to every limited request.".($this->onSqlite($context, $store)
                ? ' On SQLite, most attempts also fail under contention, when requests run at the same time, and fail open.'
                : '').self::ADVICE),
            'file', 'apc' => CheckResult::warning($this->id(), "{$counts}, which counts per server, so limits multiply by the servers, and loses counts when requests run at the same time.".self::ADVICE),
            'octane' => CheckResult::warning($this->id(), "{$counts}, which counts per server, so limits multiply by the servers.".self::ADVICE),
            'array' => CheckResult::warning($this->id(), "{$counts}, which is for tests only: each process counts on its own, and forgets."),
            'null' => CheckResult::error($this->id(), "{$counts}, which counts nothing, so no request is ever limited."),
            default => CheckResult::ok($this->id(), "{$counts}, which the package's tests do not cover."),
        };
    }

    /**
     * Whether a database store keeps its keys on SQLite: on its own connection, or the
     * app's default one.
     */
    private function onSqlite(HealthContext $context, string $store): bool
    {
        $connection = $context->config->get("cache.stores.{$store}.connection") ?? $context->config->get('database.default');

        return is_string($connection) && $context->config->get("database.connections.{$connection}.driver") === 'sqlite';
    }
}
