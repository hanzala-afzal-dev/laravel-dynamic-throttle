<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use LogicException;

/**
 * Failures remembered in APCu, shared by a server's PHP-FPM workers (SPEC-012).
 */
final readonly class ApcuFailureMemory implements FailureMemory
{
    public function __construct(
        private KeyBuilder $keys,
    ) {}

    public static function available(): bool
    {
        throw new LogicException('Not implemented yet (SPEC-012).');
    }

    public function cooldownStartedAt(): ?int
    {
        throw new LogicException('Not implemented yet (SPEC-012): '.$this->keys->prefix);
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
