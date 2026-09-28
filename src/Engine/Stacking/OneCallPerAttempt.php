<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\BatchLimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use LogicException;

/**
 * The batch's behavior for any LimiterStore, with one call per attempt and
 * per release (ADR-0026, SPEC-027): for the cache driver, Redis Cluster, and
 * stores an app wrote.
 */
final readonly class OneCallPerAttempt implements BatchLimiterStore
{
    public function __construct(
        private LimiterStore $store,
    ) {}

    public function attemptAll(array $attempts, bool $releaseAll): AttemptBatch
    {
        throw new LogicException('Not implemented yet (SPEC-027): '.$this->store::class);
    }
}
