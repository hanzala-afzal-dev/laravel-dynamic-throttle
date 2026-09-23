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
     * would be allowed, assuming no new traffic. Only called for rejected requests.
     */
    private function retryAfter(int $previous, int $current, float $elapsed, int $window, int $max): int
    {
        $untilNextWindow = $window - $elapsed;

        // Room this window has for more requests once the previous window's weight is gone.
        $headroom = $max - $current - 1;

        if ($headroom > 0) {
            // Case A: wait until the previous window's weight has decayed to the headroom.
            // A rejected request with headroom has previous > 0, so this never divides by zero.
            $wait = $untilNextWindow - $headroom * $window / $previous;
        } elseif ($current === 0) {
            // Case B with nothing counted in this window (max 1): the next window allows at once.
            $wait = $untilNextWindow;
        } else {
            // Case B: in the next window, this window's count becomes the previous one.
            // current ≥ max − 1 here, so the time into the next window is never negative.
            $wait = $untilNextWindow + $window - ($max - 1) * $window / $current;
        }

        // Rounding can put the exact wait at 0 for a request rejected by a hair.
        return max(1, (int) ceil($wait));
    }

    private function validate(int $previous, int $current, float $elapsed, int $window, int $max): void
    {
        if ($max < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, got {$max}.");
        }

        if ($window < 1) {
            throw new InvalidArgumentException("The window must be at least 1 second, got {$window}.");
        }

        // NaN fails every comparison, so it needs its own check. It is named rather than
        // converted, because PHP 8.5 warns when NaN is converted to a string.
        if (is_nan($elapsed) || $elapsed < 0 || $elapsed >= $window) {
            $shown = is_nan($elapsed) ? 'NaN' : (string) $elapsed;

            throw new InvalidArgumentException("Elapsed time must be in [0, {$window}), got {$shown}.");
        }

        if ($previous < 0 || $current < 0) {
            throw new InvalidArgumentException("Counts cannot be negative, got previous {$previous} and current {$current}.");
        }
    }
}
