<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

/**
 * A rule's limit under the scale factor: max(1, ceil(max_attempts × factor))
 * (ADR-0011 point 5, SPEC-013). The factor comes as a whole percentage, from 10
 * to 1,000, and the limit is computed in integers: floating point would make
 * 100 × 1.1 into 111. It is at least 1, since the factor is at least 0.1 and
 * any rest rounds up.
 */
final class Scale
{
    /**
     * @param  int  $maxAttempts  the rule's limit, at least 1
     * @param  int  $percent  the factor as a percentage, 150 for 1.5
     * @return int the scaled limit, which stays at PHP_INT_MAX rather than pass it
     */
    public static function limit(int $maxAttempts, int $percent): int
    {
        $hundreds = intdiv($maxAttempts, 100);
        // The last two digits scaled and rounded up: below 100,000, so they never overflow.
        $rest = intdiv($maxAttempts % 100 * $percent + 99, 100);

        if ($hundreds > intdiv(PHP_INT_MAX - $rest, $percent)) {
            return PHP_INT_MAX;
        }

        return $hundreds * $percent + $rest;
    }
}
