<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use InvalidArgumentException;

/**
 * What L1 holds of a manifest (ADR-0007, SPEC-028), as plain values, which APCu
 * copies without unserializing objects:
 * - the index built from the rules, stored with them (ADR-0023, as amended);
 * - each rule's JSON, built into a rule only when a request matches it;
 * - the exemptions' fields, which build the manifest's exemptions in full.
 *
 * @internal
 */
final readonly class ManifestSnapshot
{
    /** The entry's fields, in the order toArray() writes them. */
    private const array FIELDS = ['version', 'index', 'rules', 'exemptions', 'state', 'read_at'];

    /**
     * @param  array<array-key, mixed>  $index  the index's parts (RuleIndex::toArray()), checked when the manifest is built
     * @param  list<string>  $rules  each rule's JSON (ManifestCodec::encodeRule())
     * @param  list<array<array-key, mixed>>  $exemptions  each exemption's fields, checked when the manifest is built
     * @param  int  $readAt  when the manifest was read from the cache store, in microseconds
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
        return new self(
            $manifest->version,
            $manifest->index->toArray(),
            $manifest->rulesJson(),
            array_map(fn (CompiledExemption $exemption): array => $exemption->toArray(), $manifest->exemptions->all()),
            $manifest->state,
            $readAt,
        );
    }

    /**
     * The snapshot an entry holds, or null for one without a snapshot's fields and types
     * (AC-9). Its index and exemptions are checked when it builds the manifest.
     */
    public static function fromArray(mixed $entry): ?self
    {
        if (! is_array($entry) || array_keys($entry) !== self::FIELDS) {
            return null;
        }

        ['version' => $version, 'index' => $index, 'rules' => $rules, 'exemptions' => $exemptions, 'state' => $state, 'read_at' => $readAt] = $entry;
        $state = is_string($state) ? ManifestState::tryFrom($state) : null;

        if (! is_int($version) || $version < 1 || ! is_array($index) || ! self::isListOfStrings($rules)
            || ! self::isListOfArrays($exemptions) || ! is_int($readAt)
            || ($state !== ManifestState::Fresh && $state !== ManifestState::Stale)) {
            return null;
        }

        return new self($version, $index, $rules, $exemptions, $state, $readAt);
    }

    /**
     * The manifest. Its rules are built when a request matches them (AC-14), and its
     * index and exemptions are checked now.
     *
     * @throws InvalidArgumentException for an index or an exemption that is not what it should be (AC-9)
     */
    public function manifest(): Manifest
    {
        return new Manifest(
            $this->version,
            ManifestRules::fromJson($this->rules),
            array_map(CompiledExemption::fromArray(...), $this->exemptions),
            $this->state,
            RuleIndex::fromArray($this->index, count($this->rules)),
        );
    }

    /**
     * The entry L1 keeps: plain values, in FIELDS order.
     *
     * @return array{version: int, index: array<array-key, mixed>, rules: list<string>, exemptions: list<array<array-key, mixed>>, state: string, read_at: int}
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'index' => $this->index,
            'rules' => $this->rules,
            'exemptions' => $this->exemptions,
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
     * @phpstan-assert-if-true list<array<array-key, mixed>> $values
     */
    private static function isListOfArrays(mixed $values): bool
    {
        if (! is_array($values) || ! array_is_list($values)) {
            return false;
        }

        foreach ($values as $value) {
            if (! is_array($value)) {
                return false;
            }
        }

        return true;
    }
}
