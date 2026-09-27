<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;

/**
 * The time the counter store is skipped after it fails (ADR-0010 point 4,
 * SPEC-012), so an outage costs one failed call per cooldown instead of one
 * per request. Timed by the Clock, and kept in one or more memories: the
 * process, and APCu where a server's workers share it.
 */
final readonly class Cooldown
{
    /** @var list<FailureMemory> */
    private array $memories;

    public function __construct(
        private Clock $clock,
        /** How long the store is skipped after it fails, in seconds. 0 turns the cooldown off. */
        public int $seconds,
        FailureMemory ...$memories,
    ) {
        $this->memories = array_values($memories);
    }

    /**
     * Starts the cooldown now, or again if one is running.
     */
    public function start(): void
    {
        if ($this->seconds <= 0) {
            return;
        }

        $now = $this->clock->microseconds();

        foreach ($this->memories as $memory) {
            $memory->startCooldown($now, $this->seconds);
        }
    }

    /**
     * Whole seconds until the store may be tried again, rounded up: 0 when no cooldown runs.
     */
    public function secondsLeft(): int
    {
        $starts = [];

        foreach ($this->memories as $memory) {
            $at = $memory->cooldownStartedAt();

            if ($at !== null) {
                $starts[] = $at;
            }
        }

        // Almost every request finds none, and so reads no clock.
        if ($starts === []) {
            return 0;
        }

        $now = $this->clock->microseconds();
        // A start after now means the clock went back: that cooldown ends, so a clock step never stretches it.
        $started = array_filter($starts, fn (int $at): bool => $at <= $now);

        if ($started === []) {
            return 0;
        }

        $left = max($started) + $this->seconds * 1_000_000 - $now;

        return $left > 0 ? intdiv($left + 999_999, 1_000_000) : 0;
    }
}
