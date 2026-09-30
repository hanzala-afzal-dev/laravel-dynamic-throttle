<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\BatchLimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use LogicException;

/**
 * A counter store that counts a request in one call, as the Redis driver does,
 * and whose every call fails as a whole, as a script does when Redis is down
 * (SPEC-027 AC-9). It counts its calls, so tests can see that a cooldown skips it.
 */
final class FailingBatchStore implements BatchLimiterStore, LimiterStore
{
    public const string DOWN = 'The batch could not be counted.';

    /** How many times attemptAll() was called. */
    public int $calls = 0;

    public function attemptAll(array $attempts, bool $releaseAll): AttemptBatch
    {
        $this->calls++;

        return new AttemptBatch(failure: StorageUnavailableException::because(self::DOWN));
    }

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): never
    {
        throw new LogicException('A store that counts in one call gets no attempt of its own.');
    }

    public function release(Attempt $attempt): never
    {
        throw new LogicException('A store that counts in one call gets no release of its own.');
    }
}
