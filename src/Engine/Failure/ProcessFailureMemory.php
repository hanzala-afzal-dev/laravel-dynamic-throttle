<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Failure;

use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;

/**
 * Failures remembered in the process (SPEC-012). The provider registers one
 * instance when the app boots, so it outlives each request on Octane and in
 * queue workers. On PHP-FPM it lives for one request, and APCu is what a
 * server's workers share (ApcuFailureMemory).
 */
final class ProcessFailureMemory implements FailureMemory
{
    private ?int $cooldownStartedAt = null;

    /** @var array<string, int> when each cause was last reported */
    private array $reportedAt = [];

    public function cooldownStartedAt(): ?int
    {
        return $this->cooldownStartedAt;
    }

    public function startCooldown(int $at, int $seconds): void
    {
        $this->cooldownStartedAt = $at;
    }

    public function reportedAt(string $cause): ?int
    {
        return $this->reportedAt[$cause] ?? null;
    }

    public function markReported(string $cause, int $at, int $seconds): void
    {
        // Causes whose cooldown has passed are forgotten, so a long-lived worker keeps only recent ones.
        $this->reportedAt = array_filter($this->reportedAt, fn (int $reportedAt): bool => $at - $reportedAt < $seconds * 1_000_000);
        $this->reportedAt[$cause] = $at;
    }
}
