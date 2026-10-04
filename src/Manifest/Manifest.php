<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;

/**
 * The compiled rules, exemptions and runtime settings every request reads
 * (ADR-0007, SPEC-006, SPEC-009, SPEC-013).
 */
final readonly class Manifest
{
    /** Built from the rules, or from L1, which stores it with them and checks it when it comes back (ADR-0023, as amended). */
    public RuleIndex $index;

    /** The exemptions, grouped for the request path when the manifest loads (SPEC-009). */
    public ExemptionSet $exemptions;

    /** Bypass and the scale factor, with their end times (ADR-0011, SPEC-013). */
    public ManifestSettings $settings;

    private ManifestRules $rules;

    /**
     * @param  list<CompiledRule>|ManifestRules  $rules  sorted by priority, then id
     * @param  list<CompiledExemption>  $exemptions  sorted by id
     * @param  ?RuleIndex  $index  the index L1 stores with the rules (SPEC-028), or null to build it from them
     * @param  ?ManifestSettings  $settings  the runtime settings (SPEC-013), or null for none
     */
    public function __construct(
        public int $version,
        array|ManifestRules $rules,
        array $exemptions = [],
        public ManifestState $state = ManifestState::Fresh,
        ?RuleIndex $index = null,
        ?ManifestSettings $settings = null,
    ) {
        $this->rules = $rules instanceof ManifestRules ? $rules : ManifestRules::of($rules);
        $this->index = $index ?? RuleIndex::of($this->rules->all());
        $this->exemptions = new ExemptionSet($exemptions);
        $this->settings = $settings ?? ManifestSettings::none();
    }

    /**
     * The rule at a position of the index. From L1, it is built when first read (SPEC-028).
     *
     * @throws InvalidManifest when L1 holds a rule's JSON that is not a rule
     */
    public function rule(int $position): CompiledRule
    {
        return $this->rules->get($position);
    }

    /**
     * Every rule, in manifest order.
     *
     * @return list<CompiledRule>
     */
    public function rules(): array
    {
        return $this->rules->all();
    }

    /**
     * Each rule's JSON, for L1 (SPEC-028). A manifest from L1 gives the JSON it came as,
     * without building a rule.
     *
     * @return list<string>
     */
    public function rulesJson(): array
    {
        return $this->rules->json();
    }

    /**
     * No manifest anywhere: no rule applies (ADR-0010 point 3).
     */
    public static function missing(): self
    {
        return new self(0, [], [], ManifestState::Missing);
    }

    /**
     * The same manifest, marked stale. Its rules and index carry over, so a manifest
     * from L1 builds no rule to go stale, and so do its settings, so a bypass holds
     * through an outage until its end time (SPEC-013).
     */
    public function stale(): self
    {
        return new self($this->version, $this->rules, $this->exemptions->all(), ManifestState::Stale, $this->index, $this->settings);
    }
}
