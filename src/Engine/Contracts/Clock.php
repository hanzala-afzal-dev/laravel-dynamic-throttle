<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

/**
 * The time source for drivers that cannot read the clock inside the store (ADR-0006).
 * Engine code never reads the clock itself.
 */
interface Clock
{
    /**
     * The current Unix time in microseconds.
     */
    public function microseconds(): int;
}
