<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\CounterReader;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Throwable;

/**
 * Stands in for a counter store that could not be built, such as for an
 * unknown driver (SPEC-012). Every call fails with the reason, so a config
 * mistake counts as a store that failed: it starts the cooldown, fail-closed
 * rules answer 503, it is reported once per cooldown, and it dispatches
 * StorageUnavailable. Strict mode rethrows the reason instead. Reading its
 * counters fails the same way, so dynamic-throttle:explain shows the failure
 * policy's decision (SPEC-014).
 */
final readonly class UnavailableLimiterStore implements CounterReader, LimiterStore
{
    public function __construct(
        private Throwable $reason,
    ) {}

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): never
    {
        throw $this->failure();
    }

    public function release(Attempt $attempt): never
    {
        throw $this->failure();
    }

    public function peek(CounterKey $key, int $maxAttempts, int $windowSeconds): never
    {
        throw $this->failure();
    }

    private function failure(): StorageUnavailableException
    {
        return StorageUnavailableException::because('The counter store could not be built: '.$this->reason->getMessage(), $this->reason);
    }
}
