<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use LogicException;

/**
 * Failures remembered in the process (SPEC-012).
 */
final class ProcessFailureMemory implements FailureMemory
{
    public function cooldownStartedAt(): ?int
    {
        throw new LogicException('Not implemented yet (SPEC-012).');
    }

    public function startCooldown(int $at, int $seconds): void
    {
        throw new LogicException("Not implemented yet (SPEC-012): {$at} {$seconds}");
    }

    public function reportedAt(string $cause): ?int
    {
        throw new LogicException("Not implemented yet (SPEC-012): {$cause}");
    }

    public function markReported(string $cause, int $at, int $seconds): void
    {
        throw new LogicException("Not implemented yet (SPEC-012): {$cause} {$at} {$seconds}");
    }
}
