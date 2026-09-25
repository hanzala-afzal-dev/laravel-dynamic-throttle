<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exemptions;

use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use LogicException;

/**
 * The manifest's exemptions, grouped when it loads (SPEC-009).
 */
final readonly class ExemptionSet
{
    /**
     * @param  list<CompiledExemption>  $exemptions
     */
    public function __construct(
        private array $exemptions = [],
    ) {}

    /**
     * @return list<CompiledExemption>
     */
    public function all(): array
    {
        throw new LogicException('Not implemented yet (SPEC-009): '.count($this->exemptions));
    }

    public function match(string $ruleId, int $nowMicroseconds, ExemptionSubjects $subjects): ?string
    {
        throw new LogicException("Not implemented yet (SPEC-009): {$ruleId} {$nowMicroseconds} ".$subjects::class);
    }
}
