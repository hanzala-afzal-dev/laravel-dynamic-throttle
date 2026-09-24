<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\Manifest;
use LogicException;

final readonly class RuleMatcher
{
    public function __construct(
        private int $maxRulesPerRequest = 5,
    ) {}

    public function match(Manifest $manifest, string $method, string $path): MatchResult
    {
        throw new LogicException("Not implemented yet (SPEC-007, cap {$this->maxRulesPerRequest}).");
    }
}
