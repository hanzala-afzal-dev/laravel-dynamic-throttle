<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

/**
 * What a counter holds for one rule and client, read without counting
 * (SPEC-014): the count in the sliding window, and the attempt the next
 * request would make.
 */
final readonly class CounterReading
{
    public function __construct(
        /** The requests counted in the sliding window, the previous window's weight included, rounded up. */
        public int $count,
        /** The attempt the next request would make: whether it is allowed, what would remain after it, and its Retry-After. */
        public Attempt $next,
    ) {}

    /**
     * How many more requests the client can make now: the limit less the count,
     * at least 0. What the last response's X-RateLimit-Remaining said, when no
     * time has passed.
     */
    public function remaining(): int
    {
        return max(0, $this->next->limit - $this->count);
    }
}
