<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * The rules that match one request, in manifest order (SPEC-007).
 */
final readonly class MatchResult
{
    /**
     * @param  list<CompiledRule>  $rules  every match; the evaluator caps them (ADR-0025)
     */
    public function __construct(
        public array $rules,
    ) {}
}
