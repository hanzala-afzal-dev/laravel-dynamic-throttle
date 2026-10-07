<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use InvalidArgumentException;
use LogicException;
use Memcached;
use Throwable;

/**
 * Telemetry on any Laravel cache store (SPEC-015 decision 5): one key per minute,
 * rule and metric, created with its time to live before it is incremented, as the
 * counters are (ADR-0006). A generic cache store cannot list its keys, so the
 * flush names the rules it reads, and it keeps no offenders and no distinct
 * clients (AC-4, AC-5).
 *
 * @internal
 */
final readonly class CacheTelemetryBuffer implements TelemetryBuffer
{
    public function __construct(
        /** The cache store the numbers live in, the counters' own. */
        public Repository $cache,
        private KeyBuilder $keys,
    ) {}

    public function record(int $minute, int $weight, array $records, ?string $client = null): void
    {
        if ($weight < 1) {
            throw new InvalidArgumentException("A request stands for at least 1 request, got {$weight}.");
        }

        $increments = [];

        foreach ($records as $record) {
            foreach ($this->metrics($record->outcome) as $metric) {
                $key = $this->keys->telemetryCount($minute, $record->ruleId, $metric->value);
                $increments[$key] = ($increments[$key] ?? 0) + $weight;
            }
        }

        if ($increments === []) {
            return;
        }

        try {
            $this->withoutSerialization(function () use ($increments): void {
                foreach ($increments as $key => $amount) {
                    // Stores disagree on increment() of a missing key, so add() creates it first, with its TTL (ADR-0006).
                    $this->cache->add($key, 0, self::TTL_SECONDS);

                    // Memcached reports an outage by returning false instead of throwing.
                    if (! is_int($this->cache->increment($key, $amount))) {
                        throw StorageUnavailableException::because('The cache store did not increment '.$key.'.');
                    }
                }
            });
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The telemetry buffer could not record the request.', $exception);
        }
    }

    public function counts(int $minute, array $ruleIds): array
    {
        $keys = [];

        foreach ($ruleIds as $ruleId) {
            foreach (TelemetryMetric::cases() as $metric) {
                $keys[] = $this->keys->telemetryCount($minute, $ruleId, $metric->value);
            }
        }

        if ($keys === []) {
            return [];
        }

        try {
            $values = $this->withoutSerialization(fn (): iterable => $this->cache->getMultiple($keys));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The telemetry buffer could not read the minute.', $exception);
        }

        $this->ensureMemcachedSucceeded('read the minute');
        $values = iterator_to_array($values);
        $counts = [];

        foreach ($ruleIds as $ruleId) {
            $hits = $this->number($values, $this->keys->telemetryCount($minute, $ruleId, TelemetryMetric::Hits->value));
            $blocks = $this->number($values, $this->keys->telemetryCount($minute, $ruleId, TelemetryMetric::Blocks->value));
            $wouldBlocks = $this->number($values, $this->keys->telemetryCount($minute, $ruleId, TelemetryMetric::WouldBlocks->value));

            if ($hits + $blocks + $wouldBlocks > 0) {
                $counts[$ruleId] = new MinuteCounts($hits, $blocks, $wouldBlocks);
            }
        }

        return $counts;
    }

    /**
     * A generic cache store has no sorted set to keep them in.
     */
    public function offenders(string $ruleId, int $hour, int $limit = self::OFFENDERS): ?array
    {
        return null;
    }

    /**
     * A generic cache store has no HyperLogLog to count them in.
     */
    public function clients(string $ruleId, int $hour): ?int
    {
        return null;
    }

    public function topClients(int $hour, int $limit = 1): ?array
    {
        throw new LogicException('SPEC-016: not built yet.');
    }

    public function requests(int $hour): ?int
    {
        throw new LogicException('SPEC-016: not built yet.');
    }

    public function flushedThrough(): ?int
    {
        try {
            $minute = $this->withoutSerialization(fn (): mixed => $this->cache->get($this->keys->telemetryFlushed()));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The telemetry buffer could not read the last minute flushed.', $exception);
        }

        if ($minute === null) {
            $this->ensureMemcachedSucceeded('read the last minute flushed');
        }

        return is_numeric($minute) ? (int) $minute : null;
    }

    /**
     * Kept as long as the numbers: once they expire, there is nothing before the
     * flush's window to tell it about.
     */
    public function markFlushed(int $minute): void
    {
        try {
            $stored = $this->withoutSerialization(fn (): bool => $this->cache->put($this->keys->telemetryFlushed(), $minute, self::TTL_SECONDS));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The telemetry buffer could not keep the last minute flushed.', $exception);
        }

        if (! $stored) {
            throw StorageUnavailableException::because('The telemetry buffer could not keep the last minute flushed.');
        }
    }

    /**
     * @return list<TelemetryMetric>
     */
    private function metrics(TelemetryOutcome $outcome): array
    {
        return match ($outcome) {
            TelemetryOutcome::Counted => [TelemetryMetric::Hits],
            TelemetryOutcome::Blocked => [TelemetryMetric::Hits, TelemetryMetric::Blocks],
            TelemetryOutcome::WouldBlock => [TelemetryMetric::Hits, TelemetryMetric::WouldBlocks],
        };
    }

    /**
     * A count the minute's read gave: 0 when the key is missing.
     *
     * @param  array<array-key, mixed>  $values
     */
    private function number(array $values, string $key): int
    {
        $value = $values[$key] ?? null;

        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    /**
     * Memcached's get() and many() give null both for a missing key and for an
     * error, so a dead node would silently read a minute as empty, as in
     * CacheLimiterStore (SPEC-002 AC-20).
     */
    private function ensureMemcachedSucceeded(string $operation): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof MemcachedStore) {
            return;
        }

        $memcached = $store->getMemcached();

        if (! in_array($memcached->getResultCode(), [Memcached::RES_SUCCESS, Memcached::RES_NOTFOUND], true)) {
            throw StorageUnavailableException::because("Memcached could not {$operation}: ".$memcached->getResultMessage());
        }
    }

    /**
     * With a phpredis serializer, add() would store a serialized 0 that INCRBY cannot
     * increment, as in CacheLimiterStore (SPEC-002 AC-19).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    private function withoutSerialization(callable $callback): mixed
    {
        $store = $this->cache->getStore();

        if ($store instanceof RedisStore && ($connection = $store->connection()) instanceof PhpRedisConnection) {
            return $connection->withoutSerializationOrCompression($callback);
        }

        return $callback();
    }
}
