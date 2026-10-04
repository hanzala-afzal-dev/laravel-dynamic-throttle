<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use InvalidArgumentException;

/**
 * --for: a whole number and a unit, such as 90s, 30m, 2h or 1d, from 1 second to
 * 365 days, so a typo cannot overflow the end time (SPEC-013 decision 9).
 *
 * @internal
 */
final class Duration
{
    private const int MAX_SECONDS = 365 * 86_400;

    private const array UNITS = ['s' => 1, 'm' => 60, 'h' => 3_600, 'd' => 86_400];

    /**
     * @throws InvalidArgumentException naming what it got
     */
    public static function seconds(string $duration): int
    {
        if (preg_match('/\A([1-9]\d{0,8})([smhd])\z/', $duration, $parts) === 1) {
            $seconds = (int) $parts[1] * self::UNITS[$parts[2]];

            if ($seconds <= self::MAX_SECONDS) {
                return $seconds;
            }
        }

        throw new InvalidArgumentException("--for must be a whole number and a unit, s, m, h or d, such as 30m, from 1 second to 365 days, got \"{$duration}\".");
    }
}
