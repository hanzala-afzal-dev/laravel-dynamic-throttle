<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

use InvalidArgumentException;

/**
 * Builds every cache and Redis key the package uses (CLAUDE.md hard rule).
 */
final readonly class KeyBuilder
{
    public function __construct(
        public string $prefix = 'dt:',
    ) {
        // Memcached keys cannot contain spaces or control characters.
        if (preg_match('/\A[A-Za-z0-9:_.\-]{0,32}\z/', $prefix) !== 1) {
            throw new InvalidArgumentException('The key prefix must be at most 32 characters from A-Z, a-z, 0-9, ":", "_", "." and "-".');
        }
    }

    /**
     * The base key of one counter; the Redis driver keeps one hash here (ADR-0006).
     */
    public function counterBase(CounterKey $key, int $windowSeconds): string
    {
        return "{$this->prefix}c:{$key->ruleId}:{$windowSeconds}:{$key->identityHash}";
    }

    /**
     * The key for one window of one counter; the cache driver keeps one key per window (ADR-0006).
     */
    public function counter(CounterKey $key, int $windowSeconds, int $windowIndex): string
    {
        return $this->counterBase($key, $windowSeconds).':'.$windowIndex;
    }
}
