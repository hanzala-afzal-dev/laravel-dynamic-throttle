<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use OutOfRangeException;

/**
 * A manifest's rules by position (SPEC-028). Built by the compiler or decode(),
 * they are all built. From L1, each one is built from its JSON the first time it
 * is read, then kept, so a PHP-FPM request builds only the rules it matches. From
 * the store's read path, each one is built from its fields the same way (AC-17, as
 * amended for #195).
 *
 * @internal
 */
final class ManifestRules
{
    /**
     * @param  array<int, CompiledRule>  $built  the rules built so far, by position: every rule when $json and $fields are null
     * @param  list<string>|null  $json  each rule's JSON, from L1
     * @param  list<array<array-key, mixed>>|null  $fields  each rule's fields, from the store's read path
     */
    private function __construct(
        private array $built,
        private readonly ?array $json,
        private readonly ?array $fields = null,
    ) {}

    /**
     * @param  list<CompiledRule>  $rules
     */
    public static function of(array $rules): self
    {
        return new self($rules, null);
    }

    /**
     * @param  list<string>  $json  each rule's JSON (ManifestCodec::encodeRule())
     */
    public static function fromJson(array $json): self
    {
        return new self([], $json);
    }

    /**
     * @param  list<array<array-key, mixed>>  $fields  each rule's fields, checked when it is built
     */
    public static function fromArrays(array $fields): self
    {
        return new self([], null, $fields);
    }

    /**
     * @throws InvalidManifest for a rule's JSON or fields that are not a rule
     * @throws OutOfRangeException for a position the manifest has no rule at
     */
    public function get(int $position): CompiledRule
    {
        if (isset($this->built[$position])) {
            return $this->built[$position];
        }

        if ($this->fields !== null) {
            $fields = $this->fields[$position] ?? throw new OutOfRangeException("The manifest has no rule at position {$position}.");

            return $this->built[$position] = ManifestCodec::buildRule($fields);
        }

        $json = $this->json[$position] ?? throw new OutOfRangeException("The manifest has no rule at position {$position}.");

        return $this->built[$position] = ManifestCodec::decodeRule($json);
    }

    /**
     * Every rule, in manifest order. From L1 or the store's fields, the rules not read
     * yet are built now.
     *
     * @return list<CompiledRule>
     *
     * @throws InvalidManifest for a rule's JSON or fields that are not a rule
     */
    public function all(): array
    {
        $unbuilt = $this->json ?? $this->fields;

        return $unbuilt === null
            ? array_values($this->built)
            : array_map($this->get(...), array_keys($unbuilt));
    }

    /**
     * Each rule's JSON, for L1. From L1, it is the JSON the rules came as, and from the
     * store's fields, their JSON again, so none is built.
     *
     * @return list<string>
     */
    public function json(): array
    {
        return $this->json
            ?? ($this->fields !== null ? array_map(ManifestCodec::encodeRuleFields(...), $this->fields) : null)
            ?? array_map(ManifestCodec::encodeRule(...), array_values($this->built));
    }
}
