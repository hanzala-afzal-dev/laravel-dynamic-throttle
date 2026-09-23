<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Algorithm\SlidingWindowCounter;
use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Cache\Repository;
use InvalidArgumentException;
use Throwable;

/**
 * The generic driver: works with any Laravel cache store (ADR-0005, SPEC-002).
 *
 * One key per window. The request is counted first with an atomic increment,
 * then decided, and undone when rejected. Concurrent requests can therefore
 * never push the allowed total above the limit.
 */
final readonly class CacheLimiterStore implements LimiterStore
{
    public function __construct(
        private Repository $cache,
        private KeyBuilder $keys,
        private Clock $clock,
        private SlidingWindowCounter $counter = new SlidingWindowCounter,
    ) {}

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
    {
        // Validate before touching the store: failing inside the formula after the
        // increment would leave a phantom count (SPEC-002 AC-16).
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, got {$maxAttempts}.");
        }

        if ($windowSeconds < 1) {
            throw new InvalidArgumentException("The window must be at least 1 second, got {$windowSeconds}.");
        }

        // Integer microseconds keep the window index and the elapsed time exact.
        $now = $this->clock->microseconds();
        $windowMicroseconds = $windowSeconds * 1_000_000;
        $windowIndex = intdiv($now, $windowMicroseconds);
        $elapsed = ($now - $windowIndex * $windowMicroseconds) / 1_000_000;

        $currentKey = $this->keys->counter($key, $windowSeconds, $windowIndex);

        try {
            // Stores disagree on increment() of a missing key (Redis creates it without a
            // TTL, Memcached fails), so add() creates it first, with its TTL (ADR-0006).
            $this->cache->add($currentKey, 0, 2 * $windowSeconds + 5);
            $count = $this->cache->increment($currentKey);
            $previous = $this->cache->get($this->keys->counter($key, $windowSeconds, $windowIndex - 1));
        } catch (Throwable $exception) {
            throw new StorageUnavailableException('The limiter store could not count the request.', $exception->getCode(), previous: $exception);
        }

        // Memcached reports an outage by returning false instead of throwing.
        if (! is_int($count)) {
            throw new StorageUnavailableException('The limiter store could not count the request.');
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
     * Rejected requests are not counted (ADR-0006). If undoing fails, the rejection
     * stands and the window keeps one extra count: stricter, never looser (AC-18).
     */
    private function undo(string $windowKey): void
    {
        try {
            $this->cache->decrement($windowKey);
        } catch (Throwable) {
            // Deliberately ignored, see above.
        }
    }
}
