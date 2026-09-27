<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use LogicException;
use Throwable;

/**
 * Each cause of a failure is reported at most once per cooldown (SPEC-012 decision 5).
 */
final readonly class ReportThrottle
{
    /** @var list<FailureMemory> */
    private array $memories;

    public function __construct(
        private Clock $clock,
        private int $seconds,
        FailureMemory ...$memories,
    ) {
        $this->memories = array_values($memories);
    }

    public function allows(Throwable $exception): bool
    {
        throw new LogicException('Not implemented yet (SPEC-012): '.$exception::class.' '.$this->seconds.' '.count($this->memories).' '.$this->clock->microseconds());
    }
}
