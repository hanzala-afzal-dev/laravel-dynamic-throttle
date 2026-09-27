<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use Throwable;

/**
 * Each cause of a failure is reported at most once per cooldown (ADR-0020
 * point 1, SPEC-012 decision 5), so an outage, a broken resolver or a config
 * mistake cannot flood the app's error tracker. A cause is the class and the
 * throw site of the root exception, as error trackers group reports.
 */
final readonly class ReportThrottle
{
    /** @var list<FailureMemory> */
    private array $memories;

    public function __construct(
        private Clock $clock,
        /** The cooldown, in seconds: 0 reports every time. */
        private int $seconds,
        FailureMemory ...$memories,
    ) {
        $this->memories = array_values($memories);
    }

    /**
     * Whether to report this exception now. When it is, the cause counts as reported.
     */
    public function allows(Throwable $exception): bool
    {
        if ($this->seconds <= 0) {
            return true;
        }

        $cause = $this->cause($exception);
        $now = $this->clock->microseconds();

        foreach ($this->memories as $memory) {
            $at = $memory->reportedAt($cause);

            // A report after now means the clock went back: it no longer counts.
            if ($at !== null && $at <= $now && $now - $at < $this->seconds * 1_000_000) {
                return false;
            }
        }

        foreach ($this->memories as $memory) {
            $memory->markReported($cause, $now, $this->seconds);
        }

        return true;
    }

    private function cause(Throwable $exception): string
    {
        while (($previous = $exception->getPrevious()) instanceof Throwable) {
            $exception = $previous;
        }

        return hash('xxh128', $exception::class.'@'.$exception->getFile().':'.$exception->getLine());
    }
}
