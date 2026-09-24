<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Engine;

use Afzal\DynamicThrottle\Engine\Algorithm\SlidingWindowCounter;
use PhpBench\Attributes as Bench;

/**
 * SPEC-001: the decision alone, without storage.
 */
#[Bench\Revs(10_000)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
final readonly class SlidingWindowCounterBench
{
    private SlidingWindowCounter $counter;

    public function __construct()
    {
        $this->counter = new SlidingWindowCounter;
    }

    public function benchAllowed(): void
    {
        // Estimate 40 × 30 / 60 + 20 = 40, limit 100.
        $this->counter->evaluate(40, 20, 30.0, 60, 100);
    }

    public function benchRejected(): void
    {
        // Estimate 100 × 30 / 60 + 60 = 110: also solves for Retry-After.
        $this->counter->evaluate(100, 60, 30.0, 60, 100);
    }
}
