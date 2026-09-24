<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;

/**
 * Counter storage for the sliding-window limiter (ADR-0005).
 */
interface LimiterStore
{
    /**
     * Atomically check and count one request for a rule and identity.
     * Rejected requests are not counted (ADR-0006).
     *
     * @throws StorageUnavailableException when the store cannot be used
     */
    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt;

    /**
     * Uncount an allowed attempt when a later rule rejects the request (ADR-0008,
     * SPEC-004). The count leaves the window the attempt was counted in. Does
     * nothing for a rejected attempt, which was never counted, and never takes a
     * count below 0 or creates a key. Release each attempt at most once.
     *
     * @throws StorageUnavailableException when the store cannot be used; the attempt
     *                                     then stays counted, which is stricter, never looser
     */
    public function release(Attempt $attempt): void;
}
