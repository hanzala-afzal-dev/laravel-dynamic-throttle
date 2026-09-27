<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use LogicException;

/**
 * The time the counter store is skipped after it fails (ADR-0010 point 4, SPEC-012).
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

    public function start(): void
    {
        throw new LogicException('Not implemented yet (SPEC-012): '.count($this->memories).' '.$this->clock->microseconds());
    }

    public function secondsLeft(): int
    {
        throw new LogicException('Not implemented yet (SPEC-012): '.count($this->memories).' '.$this->clock->microseconds());
    }
}
