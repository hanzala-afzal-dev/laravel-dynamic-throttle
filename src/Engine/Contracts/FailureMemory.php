<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

/**
 * Where the package remembers failures between requests (ADR-0010, SPEC-012):
 * when the counter store's cooldown started, and when each cause was last
 * reported. Times are the Clock's microseconds. $seconds is how long an entry
 * matters, for memories that let entries expire.
 */
interface FailureMemory
{
    public function cooldownStartedAt(): ?int;

    public function startCooldown(int $at, int $seconds): void;

    public function reportedAt(string $cause): ?int;

    public function markReported(string $cause, int $at, int $seconds): void;
}
