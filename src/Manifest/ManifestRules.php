<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use OutOfRangeException;

/**
 * A manifest's rules by position (SPEC-028). Decoded from the cache store, they
 * are all built. From L1, each one is built from its JSON the first time it is
 * read, then kept, so a PHP-FPM request builds only the rules it matches.
 *
 * @internal
 */
final class ManifestRules
{
    /**
     * @param  array<int, CompiledRule>  $built  the rules built so far, by position: every rule when $json is null
     * @param  list<string>|null  $json  each rule's JSON, from L1
     */
    private function __construct(
        private array $built,
        private readonly ?array $json,
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
     * @throws InvalidManifest for a rule's JSON that is not a rule
     * @throws OutOfRangeException for a position the manifest has no rule at
     */
    public function get(int $position): CompiledRule
    {
        if (isset($this->built[$position])) {
            return $this->built[$position];
        }

        $json = $this->json[$position] ?? throw new OutOfRangeException("The manifest has no rule at position {$position}.");

        return $this->built[$position] = ManifestCodec::decodeRule($json);
    }

    /**
     * Every rule, in manifest order. From L1, the rules not read yet are built now.
     *
     * @return list<CompiledRule>
     *
     * @throws InvalidManifest for a rule's JSON that is not a rule
     */
    public function all(): array
    {
        return $this->json === null
            ? array_values($this->built)
            : array_map($this->get(...), array_keys($this->json));
    }

    /**
     * Each rule's JSON, for L1. From L1, it is the JSON the rules came as, so none is built.
     *
     * @return list<string>
     */
    public function json(): array
    {
        return $this->json ?? array_map(ManifestCodec::encodeRule(...), array_values($this->built));
    }
}
