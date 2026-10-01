<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use LogicException;

/**
 * The runtime settings the manifest holds (ADR-0011, SPEC-013). Stub until SPEC-013's implementation.
 */
final readonly class ManifestSettings
{
    public function __construct(
        public bool $bypass = false,
        public ?int $bypassUntil = null,
        public ?int $scale = null,
        public ?int $scaleUntil = null,
    ) {}

    public static function none(): self
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }

    public function bypasses(int $now): bool
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }

    public function scalePercent(int $now): int
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }

    /**
     * @return array<string, array<string, int|null>>
     */
    public function toArray(): array
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }

    public static function fromArray(mixed $settings): self
    {
        throw new LogicException('Not implemented yet (SPEC-013).');
    }
}
