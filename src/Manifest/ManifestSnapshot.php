<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use InvalidArgumentException;

/**
 * What L1 holds of a manifest (ADR-0007, SPEC-028). Its entry is plain values,
 * which APCu copies without unserializing objects:
 * - the index built from the rules, stored with them (ADR-0023, as amended);
 * - each rule's JSON, built into a rule only when a request matches it;
 * - the exemptions' fields, built into an exemption only when a request's check
 *   reaches it, and their groups (AC-16, as amended for #194);
 * - the runtime settings (SPEC-013).
 *
 * From an entry, the index, the exemptions' groups and the settings are checked at
 * once, so an entry that cannot build them is no snapshot.
 *
 * @internal
 */
final readonly class ManifestSnapshot
{
    /** The entry's fields, in the order toArray() writes them. */
    private const array FIELDS = ['version', 'index', 'rules', 'exemptions', 'exemption_groups', 'settings', 'state', 'read_at'];

    /**
     * @param  list<string>  $rules  each rule's JSON (ManifestCodec::encodeRule())
     * @param  ExemptionSet  $exemptions  their groups and their fields, each built when a check reaches it
     * @param  int  $readAt  when the manifest was read from the cache store, in microseconds
     */
    public function __construct(
        public int $version,
        public RuleIndex $index,
        public array $rules,
        public ExemptionSet $exemptions,
        public ManifestState $state,
        public int $readAt,
        public ManifestSettings $settings = new ManifestSettings,
    ) {}

    public static function of(Manifest $manifest, int $readAt): self
    {
        return new self($manifest->version, $manifest->index, $manifest->rulesJson(), $manifest->exemptions, $manifest->state, $readAt, $manifest->settings);
    }

    /**
     * The snapshot of an entry, or null for an entry that is not one (AC-9): without a
     * snapshot's fields and types, or with an index, exemptions or settings that cannot be built.
     * Its rules' JSON is checked only when a request matches them (AC-14).
     */
    public static function fromArray(mixed $entry): ?self
    {
        if (! is_array($entry) || array_keys($entry) !== self::FIELDS) {
            return null;
        }

        ['version' => $version, 'index' => $index, 'rules' => $rules, 'exemptions' => $exemptions, 'exemption_groups' => $groups, 'settings' => $settings, 'state' => $state, 'read_at' => $readAt] = $entry;
        $state = is_string($state) ? ManifestState::tryFrom($state) : null;

        if (! is_int($version) || $version < 1 || ! is_array($index) || ! self::isListOfStrings($rules)
            || ! self::isListOfPlainArrays($exemptions) || ! is_array($groups) || ! is_int($readAt)
            || ($state !== ManifestState::Fresh && $state !== ManifestState::Stale)) {
            return null;
        }

        try {
            return new self($version, RuleIndex::fromArray($index, count($rules)), $rules, ExemptionSet::fromParts($groups, $exemptions), $state, $readAt, ManifestSettings::fromArray($settings));
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * The manifest. Its rules are built when a request matches them (AC-14).
     */
    public function manifest(): Manifest
    {
        return new Manifest($this->version, ManifestRules::fromJson($this->rules), $this->exemptions, $this->state, $this->index, $this->settings);
    }

    /**
     * The entry L1 keeps: plain values, in FIELDS order.
     *
     * @return array{version: int, index: array{literal: array<string, array<array-key, list<int>>>, wildcard: array<string, list<int>>, patterns: list<string>}, rules: list<string>, exemptions: list<array<array-key, mixed>>, exemption_groups: array<array-key, array<array-key, mixed>>, settings: array<string, array<string, int|null>>, state: string, read_at: int}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'index' => $this->index->toArray(),
            'rules' => $this->rules,
            'exemptions' => $this->exemptions->arrays(),
            'exemption_groups' => $this->exemptions->toArray(),
            'settings' => $this->settings->toArray(),
            'state' => $this->state->value,
            'read_at' => $this->readAt,
        ];
    }

    /**
     * @phpstan-assert-if-true list<string> $values
     */
    private static function isListOfStrings(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_string($value)) {
                return false;
            }
        }

        return true;
    }

    /**
     * A list of arrays of strings, integers and nulls, as an exemption's fields are. An
     * exemption is built only when a check reaches it (AC-16), so this is where an entry
     * with an object in it stops being a snapshot (AC-9).
     *
     * @phpstan-assert-if-true list<array<array-key, string|int|null>> $values
     */
    private static function isListOfPlainArrays(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_array($value)) {
                return false;
            }

            foreach ($value as $field) {
                if (! is_string($field) && ! is_int($field) && $field !== null) {
                    return false;
                }
            }
        }

        return true;
    }
}
