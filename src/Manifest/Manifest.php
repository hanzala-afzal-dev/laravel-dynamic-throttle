<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

/**
 * The compiled rules every request reads (ADR-0007, SPEC-006).
 */
final readonly class Manifest
{
    /**
     * @param  list<CompiledRule>  $rules  sorted by priority, then id
     */
    public function __construct(
        public int $version,
        public array $rules,
        public ManifestState $state = ManifestState::Fresh,
    ) {}

    /**
     * No manifest anywhere: no rule applies (ADR-0010 point 3).
     */
    public static function missing(): self
    {
        return new self(0, [], ManifestState::Missing);
    }

    public function stale(): self
    {
        return new self($this->version, $this->rules, ManifestState::Stale);
    }
}
