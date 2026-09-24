<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Internal;

use InvalidArgumentException;

/**
 * Checks every driver runs before touching its store (SPEC-002 AC-16).
 *
 * @internal
 */
final class AttemptGuard
{
    /**
     * ADR-0015: rules have windows from 1 second to 1 day.
     *
     * A constant declaration never runs, so coverage cannot credit this line to
     * AttemptGuardTest, which fails when the value is 86,399 or 86,401.
     */
    public const int MAX_WINDOW_SECONDS = 86_400; // @pest-mutate-ignore: IncrementInteger,DecrementInteger

    public static function check(int $maxAttempts, int $windowSeconds): void
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException("The limit must be at least 1, got {$maxAttempts}.");
        }

        if ($windowSeconds < 1 || $windowSeconds > self::MAX_WINDOW_SECONDS) {
            throw new InvalidArgumentException('The window must be from 1 to '.self::MAX_WINDOW_SECONDS." seconds, got {$windowSeconds}.");
        }
    }
}
