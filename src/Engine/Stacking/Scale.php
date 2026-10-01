<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use LogicException;

/**
 * A rule's limit under the scale factor (ADR-0011 point 5, SPEC-013). Stub until SPEC-013's implementation.
 */
final class Scale
{
    public static function limit(int $maxAttempts, int $percent): int
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }
}
