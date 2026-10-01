<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use LogicException;

/**
 * The compiled rules and exemptions every request reads (ADR-0007, SPEC-006, SPEC-009).
 */
final readonly class Manifest
{
    /** Built from the rules, never stored, so it cannot disagree with them (ADR-0023). */
    public RuleIndex $index;

    /** The exemptions, grouped for the request path when the manifest loads (SPEC-009). */
    public ExemptionSet $exemptions;

    /**
     * @param  list<CompiledRule>  $rules  sorted by priority, then id
     * @param  list<CompiledExemption>  $exemptions  sorted by id
     */
    public function __construct(
        public int $version,
        public array|ManifestRules $rules,
        array $exemptions = [],
        public ManifestState $state = ManifestState::Fresh,
        ?RuleIndex $index = null,
    ) {
        $this->index = $index instanceof RuleIndex || $rules instanceof ManifestRules
            ? throw new LogicException('Not implemented yet (SPEC-028)')
            : RuleIndex::of($rules);
        $this->exemptions = new ExemptionSet($exemptions);
    }

    public function rule(int $position): CompiledRule
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$position);
    }

    /**
     * @return list<CompiledRule>
     */
    public function rules(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028)');
    }

    /**
     * @return list<string>
     */
    public function rulesJson(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028)');
    }

    /**
     * No manifest anywhere: no rule applies (ADR-0010 point 3).
     */
    public static function missing(): self
    {
        return new self(0, [], [], ManifestState::Missing);
    }

    public function stale(): self
    {
        return new self($this->version, $this->rules, $this->exemptions->all(), ManifestState::Stale);
    }
}
