<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use InvalidArgumentException;
use LogicException;

/**
 * One exemption as the request path sees it (SPEC-009 AC-8).
 */
final readonly class CompiledExemption
{
    public function __construct(
        public string $id,
        public ?string $rule,
        public ExemptionSubject $subject,
        public ?string $parameter,
        public string $match,
        public ?int $expiresAt,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromArray(array $data): self
    {
        throw new LogicException('Not implemented yet (SPEC-009): '.count($data));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$this->id}");
    }
}
