<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * The rules that apply to one request, in manifest order (SPEC-007).
 */
final readonly class MatchResult
{
    /**
     * @param  list<CompiledRule>  $rules  at most matching.max_rules_per_request
     * @param  int  $ignored  further matches beyond the cap
     */
    public function __construct(
        public array $rules,
        public int $ignored,
    ) {}
}
