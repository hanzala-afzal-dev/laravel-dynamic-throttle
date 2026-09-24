<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;

/**
 * The compiled rules every request reads (ADR-0007, SPEC-006).
 */
final readonly class Manifest
{
    /** Built from the rules, never stored, so it cannot disagree with them (ADR-0023). */
    public RuleIndex $index;

    /**
     * @param  list<CompiledRule>  $rules  sorted by priority, then id
     */
    public function __construct(
        public int $version,
        public array $rules,
        public ManifestState $state = ManifestState::Fresh,
    ) {
        $this->index = RuleIndex::of($rules);
    }

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
