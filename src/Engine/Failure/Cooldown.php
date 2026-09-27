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
        if ($this->seconds <= 0) {
            return 0;
        }

        $now = $this->clock->microseconds();
        $startedAt = null;

        foreach ($this->memories as $memory) {
            $at = $memory->cooldownStartedAt();

            // A start after now means the clock went back: that cooldown ends, so a clock step never stretches it.
            if ($at !== null && $at <= $now && ($startedAt === null || $at > $startedAt)) {
                $startedAt = $at;
            }
        }

        $left = $startedAt === null ? 0 : $startedAt + $this->seconds * 1_000_000 - $now;

        return $left > 0 ? intdiv($left + 999_999, 1_000_000) : 0;
    }
}
