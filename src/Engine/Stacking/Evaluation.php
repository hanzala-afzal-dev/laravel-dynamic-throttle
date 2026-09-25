<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * What evaluating one request's rules did (SPEC-007). The facts headers, the
 * 429, events and the failure policy are built from (SPEC-010 to SPEC-012).
 */
final readonly class Evaluation
{
    /**
     * @param  list<RuleAttempt>  $attempts  every attempt that counted, in order; released ones are marked
     * @param  list<RuleFailure>  $failures  failed rules, in order: identities that could not be resolved (SPEC-008), then the failed attempt that stopped evaluation, if any
     * @param  list<RuleAttempt>  $wouldBlock  dry-run rules that would have rejected (not counted)
     * @param  list<CompiledRule>  $skipped  rules that did not apply to this request
     * @param  list<RuleFailure>  $releaseFailures  counts that could not be released (ADR-0021)
     * @param  list<CompiledRule>  $ignored  rules over their mode's cap: not attempted (ADR-0025)
     * @param  list<RuleExemption>  $exempted  rules an exemption freed the client from: not attempted (SPEC-009)
     * @param  list<RuleFailure>  $exemptionFailures  exemption checks that failed, and so exempted nothing (SPEC-009)
     */
    private function __construct(
        public EvaluationOutcome $outcome,
        public array $attempts,
        public ?RuleAttempt $rejection,
        public array $failures,
        public array $wouldBlock,
        public array $skipped,
        public array $releaseFailures,
        public array $ignored,
        public array $exempted,
        public array $exemptionFailures,
    ) {}

    /**
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     * @param  list<CompiledRule>  $ignored
     * @param  list<RuleExemption>  $exempted
     * @param  list<RuleFailure>  $exemptionFailures
     */
    public static function allowed(array $attempts, array $wouldBlock, array $skipped, array $ignored, array $exempted, array $exemptionFailures): self
    {
        return new self(EvaluationOutcome::Allowed, $attempts, null, [], $wouldBlock, $skipped, [], $ignored, $exempted, $exemptionFailures);
    }

    /**
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleFailure>  $failures  identities that could not be resolved before the rejection
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     * @param  list<RuleFailure>  $releaseFailures
     * @param  list<CompiledRule>  $ignored
     * @param  list<RuleExemption>  $exempted
     * @param  list<RuleFailure>  $exemptionFailures
     */
    public static function rejected(RuleAttempt $rejection, array $attempts, array $failures, array $wouldBlock, array $skipped, array $releaseFailures, array $ignored, array $exempted, array $exemptionFailures): self
    {
        return new self(EvaluationOutcome::Rejected, $attempts, $rejection, $failures, $wouldBlock, $skipped, $releaseFailures, $ignored, $exempted, $exemptionFailures);
    }

    /**
     * @param  non-empty-list<RuleFailure>  $failures
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     * @param  list<CompiledRule>  $ignored
     * @param  list<RuleExemption>  $exempted
     * @param  list<RuleFailure>  $exemptionFailures
     */
    public static function failed(array $failures, array $attempts, array $wouldBlock, array $skipped, array $ignored, array $exempted, array $exemptionFailures): self
    {
        return new self(EvaluationOutcome::Failed, $attempts, null, $failures, $wouldBlock, $skipped, [], $ignored, $exempted, $exemptionFailures);
    }
}
