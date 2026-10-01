<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use LogicException;

/**
 * What L1 holds of a manifest (ADR-0007, SPEC-028), as plain values.
 *
 * @internal
 */
final readonly class ManifestSnapshot
{
    /**
     * @param  array<array-key, mixed>  $index
     * @param  list<string>  $rules
     * @param  list<array<array-key, mixed>>  $exemptions
     */
    public function __construct(
        public int $version,
        public array $index,
        public array $rules,
        public array $exemptions,
        public ManifestState $state,
        public int $readAt,
    ) {}

    public static function of(Manifest $manifest, int $readAt): self
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$manifest->version.' at '.$readAt);
    }

    public function manifest(): Manifest
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$this->version);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$this->version);
    }

    public static function fromArray(mixed $entry): ?self
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.get_debug_type($entry));
    }
}
