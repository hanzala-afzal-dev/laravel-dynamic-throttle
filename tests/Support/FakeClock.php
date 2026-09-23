<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;

/**
 * A clock the test controls (ADR-0016: no sleep() in tests).
 */
final class FakeClock implements Clock
{
    /** 1,800,000,000 s: a multiple of 30, 60 and 3600, so windows start on round numbers. */
    public const int T0 = 1_800_000_000;

    public function __construct(
        private int $microseconds = self::T0 * 1_000_000,
    ) {}

    public function microseconds(): int
    {
        return $this->microseconds;
    }

    public function setSeconds(int $seconds): void
    {
        $this->microseconds = $seconds * 1_000_000;
    }

    public function setMicroseconds(int $microseconds): void
    {
        $this->microseconds = $microseconds;
    }
}
