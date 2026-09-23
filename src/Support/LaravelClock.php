<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Illuminate\Support\Facades\Date;

/**
 * The application clock, so Laravel's travel() helpers work (ADR-0006).
 */
final readonly class LaravelClock implements Clock
{
    public function microseconds(): int
    {
        // "U" is Unix seconds and "u" six digits of microseconds: exact, no float rounding.
        return (int) Date::now()->format('Uu');
    }
}
