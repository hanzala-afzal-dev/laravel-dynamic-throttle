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
     * @param  list<RuleAttempt>  $wouldBlock  dry-run rules that would have rejected (not counted)
     * @param  list<CompiledRule>  $skipped  rules that did not apply to this request
     * @param  list<RuleFailure>  $releaseFailures  counts that could not be released (ADR-0021)
     */
    private function __construct(
        public EvaluationOutcome $outcome,
        public array $attempts,
        public ?RuleAttempt $rejection,
        public ?RuleFailure $failure,
        public array $wouldBlock,
        public array $skipped,
        public array $releaseFailures,
    ) {}

    /**
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     */
    public static function allowed(array $attempts, array $wouldBlock, array $skipped): self
    {
        return new self(EvaluationOutcome::Allowed, $attempts, null, null, $wouldBlock, $skipped, []);
    }

    /**
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     * @param  list<RuleFailure>  $releaseFailures
     */
    public static function rejected(RuleAttempt $rejection, array $attempts, array $wouldBlock, array $skipped, array $releaseFailures): self
    {
        return new self(EvaluationOutcome::Rejected, $attempts, $rejection, null, $wouldBlock, $skipped, $releaseFailures);
    }

    /**
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     */
    public static function failed(RuleFailure $failure, array $attempts, array $wouldBlock, array $skipped): self
    {
        return new self(EvaluationOutcome::Failed, $attempts, null, $failure, $wouldBlock, $skipped, []);
    }
}
