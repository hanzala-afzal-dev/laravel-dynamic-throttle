<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;

/**
 * A counter store that counts all of a request's attempts in one call
 * (ADR-0026, SPEC-027). Optional: the evaluator makes one call per attempt,
 * with the same behavior, for a store that does not implement it.
 */
interface BatchLimiterStore
{
    /**
     * Counts the attempts in order. At the first enforcing attempt that is
     * rejected, stops, and releases every count made for the request. With
     * $releaseAll, releases every count at the end even without a rejection.
     * Never throws a StorageUnavailableException: a failure is part of the result.
     *
     * @param  list<PlannedAttempt>  $attempts
     */
    public function attemptAll(array $attempts, bool $releaseAll): AttemptBatch;
}
