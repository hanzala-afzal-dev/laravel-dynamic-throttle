<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\CompiledRule;
use LogicException;

final readonly class RuleIndex
{
    /**
     * @param  list<CompiledRule>  $rules
     */
    public static function of(array $rules): self
    {
        throw new LogicException('Not implemented yet (SPEC-007).');
    }

    /**
     * @return list<int>
     */
    public function candidates(string $method, string $path): array
    {
        throw new LogicException('Not implemented yet (SPEC-007).');
    }
}
