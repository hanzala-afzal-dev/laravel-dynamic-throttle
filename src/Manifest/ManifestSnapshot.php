<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use LogicException;

/**
 * What L1 holds of a manifest (ADR-0007, SPEC-028): its parts as compiled,
 * never the rule index or the exemption groups, which are built again from
 * them when the manifest loads (ADR-0023).
 *
 * @internal
 */
final readonly class ManifestSnapshot
{
    /**
     * @param  list<CompiledRule>  $rules
     * @param  list<CompiledExemption>  $exemptions
     * @param  int  $readAt  when the manifest was read from the cache store, in microseconds
     */
    public function __construct(
        public int $version,
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
}
