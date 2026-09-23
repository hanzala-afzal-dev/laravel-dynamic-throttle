<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

/**
 * The outcome of LimiterStore::attempt() for one request.
 */
final readonly class Attempt
{
    public function __construct(
        public CounterKey $key,
        /** The limit the request was checked against. */
        public int $limit,
        public int $windowSeconds,
        /** floor(now / windowSeconds): the window the request was counted in. */
        public int $windowIndex,
        public bool $allowed,
        public int $remaining,
        /** Whole seconds to wait before retrying: at least 1 when rejected, 0 when allowed. */
        public int $retryAfter,
    ) {}
}
