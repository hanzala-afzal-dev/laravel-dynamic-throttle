<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Algorithm;

use LogicException;

/**
 * Sliding-window counter decision (ADR-0006, SPEC-001).
 */
final readonly class SlidingWindowCounter
{
    public function evaluate(int $previous, int $current, float $elapsed, int $windowSeconds, int $maxAttempts): Evaluation
    {
        throw new LogicException('Not implemented yet (SPEC-001).');
    }
}
