<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Algorithm;

use InvalidArgumentException;

/**
 * Sliding-window counter decision (ADR-0006, SPEC-001).
 *
 * The previous window counts in proportion to how much of it still overlaps
 * the sliding window:
 *
 *     estimate = previous × (window − elapsed) / window + current
 *
 * A request is allowed when estimate + 1 ≤ max. This class is the reference
 * implementation; the Redis Lua script is a port of it (SPEC-003).
 */
final readonly class SlidingWindowCounter
{
    /**
     * @param  int  $previous  Requests counted in the previous window.
     * @param  int  $current  Requests counted in the current window, before this one.
     * @param  float  $elapsed  Seconds since the current window started, in [0, window).
     */
    public function evaluate(int $previous, int $current, float $elapsed, int $windowSeconds, int $maxAttempts): Evaluation
    {
        $this->validate($previous, $current, $elapsed, $windowSeconds, $maxAttempts);

        $estimate = $previous * ($windowSeconds - $elapsed) / $windowSeconds + $current;

        if ($estimate + 1 <= $maxAttempts) {
            return new Evaluation(
                allowed: true,
                remaining: $maxAttempts - (int) ceil($estimate + 1),
                retryAfter: 0,
                estimate: $estimate,
            );
        }

        return new Evaluation(
            allowed: false,
            remaining: 0,
            retryAfter: $this->retryAfter($previous, $current, $elapsed, $windowSeconds, $maxAttempts),
            estimate: $estimate,
        );
    }

    /**
     * The smallest whole number of seconds (at least 1) after which a request
     * would be allowed, assuming no new traffic.
     */
    private function retryAfter(int $previous, int $current, float $elapsed, int $window, int $max): int
    {
        $untilNextWindow = $window - $elapsed;

        // Case A: the previous window's weight decays enough before this window ends.
        if ($current + 1 <= $max && $previous > 0) {
            $wait = $untilNextWindow - ($max - $current - 1) * $window / $previous;

            if ($wait < $untilNextWindow) {
                return max(1, (int) ceil($wait));
            }
        }

        // Case B: in the next window, this window's count becomes the previous one.
        // With nothing counted in this window, the next window allows at once.
        $intoNextWindow = $current === 0 ? 0.0 : max(0.0, $window - ($max - 1) * $window / $current);

        return max(1, (int) ceil($untilNextWindow + $intoNextWindow));
    }

    private function validate(int $previous, int $current, float $elapsed, int $window, int $max): void
    {
        if ($max < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, got {$max}.");
        }

        if ($window < 1) {
            throw new InvalidArgumentException("The window must be at least 1 second, got {$window}.");
        }

        // NaN fails every comparison, so it needs its own check.
        if (is_nan($elapsed) || $elapsed < 0 || $elapsed >= $window) {
            throw new InvalidArgumentException("Elapsed time must be in [0, {$window}), got {$elapsed}.");
        }

        if ($previous < 0 || $current < 0) {
            throw new InvalidArgumentException("Counts cannot be negative, got previous {$previous} and current {$current}.");
        }
    }
}
