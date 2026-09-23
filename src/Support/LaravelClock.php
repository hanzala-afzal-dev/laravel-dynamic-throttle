<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use LogicException;

/**
 * The application clock, so Laravel's travel() helpers work (ADR-0006).
 */
final readonly class LaravelClock implements Clock
{
    public function microseconds(): int
    {
        throw new LogicException('Not implemented yet (SPEC-002).');
    }
}
