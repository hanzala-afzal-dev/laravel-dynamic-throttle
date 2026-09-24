<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Algorithm\SlidingWindowCounter;
use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Memcached;
use Throwable;

/**
 * The generic driver for Laravel cache stores (ADR-0005, SPEC-002).
 *
 * One key per window. The request is counted first with an atomic increment,
 * then decided, and undone when rejected, so concurrent requests can never push
 * the allowed total above the limit on stores with an atomic increment (Redis,
 * Valkey, Memcached, DynamoDB, MySQL and PostgreSQL). File and APCu stores are
 * not atomic: see SPEC-002's edge cases.
 */
final readonly class CacheLimiterStore implements LimiterStore
{
    public function __construct(
        /** The cache store the counters live in; public so driver selection can be checked. */
        public Repository $cache,
        private KeyBuilder $keys,
        private Clock $clock,
        private SlidingWindowCounter $counter = new SlidingWindowCounter,
    ) {}

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
    {
        // Before touching the store: failing inside the formula after the increment
        // would leave a phantom count (SPEC-002 AC-16).
        AttemptGuard::check($maxAttempts, $windowSeconds);

        // Integer microseconds keep the window index and the elapsed time exact.
        $now = $this->clock->microseconds();
        $windowMicroseconds = $windowSeconds * 1_000_000;
        $windowIndex = intdiv($now, $windowMicroseconds);
        $elapsed = ($now - $windowIndex * $windowMicroseconds) / 1_000_000;

        $currentKey = $this->keys->counter($key, $windowSeconds, $windowIndex);
        $previousKey = $this->keys->counter($key, $windowSeconds, $windowIndex - 1);

        try {
            [$count, $previous] = $this->withoutSerialization(function () use ($currentKey, $previousKey, $windowSeconds): array {
                // Stores disagree on increment() of a missing key (Redis creates it without a
                // TTL, Memcached fails), so add() creates it first, with its TTL (ADR-0006).
                $this->cache->add($currentKey, 0, 2 * $windowSeconds + 5);

                return [$this->cache->increment($currentKey), $this->cache->get($previousKey)];
            });
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The limiter store could not count the request.', $exception);
        }

        // Memcached reports an outage by returning false instead of throwing.
        if (! is_int($count)) {
            throw StorageUnavailableException::because('The limiter store could not count the request.');
        }

        if ($previous === null) {
            $this->ensureReadSucceeded('the previous window');
        }

        $evaluation = $this->counter->evaluate(
            previous: is_numeric($previous) ? max(0, (int) $previous) : 0,
            current: max(0, $count - 1),
            elapsed: $elapsed,
            windowSeconds: $windowSeconds,
            maxAttempts: $maxAttempts,
        );

        if (! $evaluation->allowed) {
            $this->undo($currentKey);
        }

        return new Attempt(
            key: $key,
            limit: $maxAttempts,
            windowSeconds: $windowSeconds,
            windowIndex: $windowIndex,
            allowed: $evaluation->allowed,
            remaining: $evaluation->remaining,
            retryAfter: $evaluation->retryAfter,
        );
    }

    /**
     * Reads the window's count, and decrements it only when it is above 0 (SPEC-004).
     * A missing key expired or was evicted, and decrementing it would create it with
     * -1 and no expiry on Redis and file stores. The read and the decrement are two
     * operations, so two releases racing on a count of 1 could both decrement it.
     */
    public function release(Attempt $attempt): void
    {
        // A rejected attempt was never counted.
        if (! $attempt->allowed) {
            return;
        }

        $windowKey = $this->keys->counter($attempt->key, $attempt->windowSeconds, $attempt->windowIndex);

        try {
            $count = $this->withoutSerialization(fn (): mixed => $this->cache->get($windowKey));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The limiter store could not release the request.', $exception);
        }

        if ($count === null) {
            $this->ensureReadSucceeded('the window');
        }

        if (! is_numeric($count) || $count < 1) {
            return;
        }

        try {
            $this->withoutSerialization(fn (): int|bool => $this->cache->decrement($windowKey));
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The limiter store could not release the request.', $exception);
        }
    }

    /**
     * Rejected requests are not counted (ADR-0006). If undoing fails, the rejection
     * stands and the window keeps one extra count: stricter, never looser (AC-18).
     */
    private function undo(string $windowKey): void
    {
        try {
            $this->withoutSerialization(fn (): int|bool => $this->cache->decrement($windowKey));
        } catch (Throwable) {
            // Deliberately ignored, see above.
        }
    }

    /**
     * Memcached's get() returns null both for a missing key and for an error, so a
     * dead node would silently count a window as 0 (SPEC-002 AC-20, SPEC-004 AC-12).
     */
    private function ensureReadSucceeded(string $window): void
    {
        $store = $this->cache->getStore();

        if (! $store instanceof MemcachedStore) {
            return;
        }

        $memcached = $store->getMemcached();

        if (! in_array($memcached->getResultCode(), [Memcached::RES_SUCCESS, Memcached::RES_NOTFOUND], true)) {
            throw StorageUnavailableException::because("Memcached could not read {$window}: ".$memcached->getResultMessage());
        }
    }

    /**
     * With a phpredis serializer, add() would store a serialized 0 that INCRBY cannot
     * increment. Laravel's own RateLimiter bypasses serialization the same way (AC-19).
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
