<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

/**
 * One health check (SPEC-016). It reads, and never writes (AC-3).
 *
 * @internal
 */
interface Check
{
    /** The check's id, public once released (decision 2). */
    public function id(): string;

    /**
     * What the check found. A check may throw: the report then gives it as an error,
     * and the other checks still run.
     */
    public function run(HealthContext $context): CheckResult;
}
