<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * The first step of evaluating a request's rules, made without the store
 * (ADR-0026, SPEC-014): the attempts the store will count, and why each other
 * matched rule counts nothing.
 *
 * @internal
 */
final readonly class Plan
{
    /**
     * @param  list<CompiledRule>  $rules  the rules that count the request, in evaluation order
     * @param  list<PlannedAttempt>  $attempts  their attempts, in the same order
     * @param  list<int>  $positions  their positions among the matched rules, in the same order
     * @param  array<int, RuleFailure>  $failures  identities that could not be resolved, by the rule's position among the matched rules (SPEC-008)
     * @param  list<CompiledRule>  $skipped  rules the request has no identity for (ADR-0022)
     * @param  list<CompiledRule>  $ignored  rules over their mode's cap (ADR-0025)
     * @param  list<RuleExemption>  $exempted  rules an exemption frees the client from (SPEC-009)
     * @param  list<RuleFailure>  $exemptionFailures  exemption checks that failed, and so exempted nothing (SPEC-009)
     * @param  ?CompiledRule  $stop  an enforcing fail-closed rule whose identity could not be resolved: the plan ends there (SPEC-012)
     */
    public function __construct(
        public array $rules,
        public array $attempts,
        public array $positions,
        public array $failures,
        public array $skipped,
        public array $ignored,
        public array $exempted,
        public array $exemptionFailures,
        public ?CompiledRule $stop,
    ) {}
}
