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
}
