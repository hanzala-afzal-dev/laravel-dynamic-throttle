<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use InvalidArgumentException;
use OutOfRangeException;

/**
 * A manifest's exemptions by position (SPEC-028 AC-16 to AC-19, as amended for
 * #194 and #195). Built by the compiler or decode(), they are all built. From L1
 * or the store's read path, each one is built from its fields the first time it
 * is read, then kept, so a request builds only the exemptions its checks reach.
 *
 * @internal
 */
final class ManifestExemptions
{
    /**
     * @param  array<int, CompiledExemption>  $built  the exemptions built so far, by position: every one when $fields is null
     * @param  list<array<array-key, mixed>>|null  $fields  each exemption's fields, as CompiledExemption::toArray() writes them
     */
    private function __construct(
        private array $built,
        private readonly ?array $fields,
    ) {}

    /**
     * @param  list<CompiledExemption>  $exemptions
     */
    public static function of(array $exemptions): self
    {
        return new self($exemptions, null);
    }

    /**
     * @param  list<array<array-key, mixed>>  $fields  each exemption's fields, checked when it is built
     */
    public static function fromArrays(array $fields): self
    {
        return new self([], $fields);
    }

    /**
     * @throws InvalidManifest for fields that are not an exemption
     * @throws OutOfRangeException for a position the manifest has no exemption at
     */
    public function get(int $position): CompiledExemption
    {
        if (isset($this->built[$position])) {
            return $this->built[$position];
        }

        $fields = $this->fields[$position] ?? throw new OutOfRangeException("The manifest has no exemption at position {$position}.");

        try {
            return $this->built[$position] = CompiledExemption::fromArray($fields);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidManifest('An exemption is invalid: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }

    /**
     * Every exemption, in manifest order. From fields, the ones not read yet are built now.
     *
     * @return list<CompiledExemption>
     *
     * @throws InvalidManifest for fields that are not an exemption
     */
    public function all(): array
    {
        return $this->fields === null
            ? array_values($this->built)
            : array_map($this->get(...), array_keys($this->fields));
    }

    /**
     * How many exemptions there are, without building any (AC-19).
     */
    public function count(): int
    {
        return count($this->fields ?? $this->built);
    }

    /**
     * Each exemption's fields, for L1. From fields, they are the fields the exemptions
     * came as, so none is built.
     *
     * @return list<array<array-key, mixed>>
     */
    public function arrays(): array
    {
        return $this->fields ?? array_map(fn (CompiledExemption $exemption): array => $exemption->toArray(), array_values($this->built));
    }
}
