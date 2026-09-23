<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Algorithm\SlidingWindowCounter;
use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Cache\Repository;
use LogicException;

/**
 * The generic driver: works with any Laravel cache store (ADR-0005, SPEC-002).
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
        throw new LogicException('Not implemented yet (SPEC-002).');
    }
}
