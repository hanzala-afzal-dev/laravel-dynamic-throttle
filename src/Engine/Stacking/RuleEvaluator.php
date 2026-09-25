<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * Counts a request on each of its matched rules, in order (ADR-0008, SPEC-007).
 * The first enforcing rule that rejects it stops evaluation, and every count
 * made so far is released, so a rejected request counts nowhere. A rule the
 * client is exempt from counts nothing (SPEC-009).
 */
final readonly class RuleEvaluator
{
    public function __construct(
        private LimiterStore $store,
        /**
         * matching.max_rules_per_request: store attempts per request for enforcing
         * rules, and as many again for dry-run rules (ADR-0025).
         */
        private int $maxRulesPerRequest = 5,
    ) {}

    /**
     * Never throws a StorageUnavailableException or an IdentityUnavailableException.
     * An identity that cannot be resolved fails only its own rule (SPEC-008), a
     * failed attempt stops evaluation, and a failed release is listed (ADR-0021).
     * So is a failed exemption check, which exempts nothing (SPEC-009).
     *
     * @param  list<CompiledRule>  $rules  the matched rules, in manifest order
     */
    public function evaluate(array $rules, IdentityResolver $identities): Evaluation
    {
        $attempts = [];
        $failures = [];
        $wouldBlock = [];
        $skipped = [];
        $ignored = [];
        $exempted = [];
        $exemptionFailures = [];
        // Attempts left for each mode: a rule that does not apply takes none, and a
        // dry-run rule never takes an enforcing rule's (ADR-0025).
        $slots = [RuleMode::Enforce->value => $this->maxRulesPerRequest, RuleMode::DryRun->value => $this->maxRulesPerRequest];

        foreach ($rules as $rule) {
            if ($slots[$rule->mode->value] === 0) {
                $ignored[] = $rule;

                continue;
            }

            try {
                $exemption = $identities->exemption($rule);
            } catch (IdentityUnavailableException $exception) {
                // A check that fails exempts nothing, so it can never switch a limit off.
                $exemptionFailures[] = new RuleFailure($rule, $exception);
                $exemption = null;
            }

            // An exempt rule does not apply to the request: no attempt, no slot, no identity.
            if ($exemption !== null) {
                $exempted[] = new RuleExemption($rule, $exemption);

                continue;
            }

            try {
                $identity = $identities->resolve($rule);
            } catch (IdentityUnavailableException $exception) {
                // Only this rule fails, so a client that breaks one scope is still counted by the others.
                $failures[] = new RuleFailure($rule, $exception);

                continue;
            }

            if ($identity === null) {
                $skipped[] = $rule;

                continue;
            }

            try {
                $attempt = $this->store->attempt(new CounterKey($rule->id, $identity), $rule->maxAttempts, $rule->windowSeconds);
            } catch (StorageUnavailableException $exception) {
                // The later rules count on the same store, so they would fail too.
                return Evaluation::failed([...$failures, new RuleFailure($rule, $exception)], $attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures);
            }

            $slots[$rule->mode->value]--;

            if ($attempt->allowed) {
                $attempts[] = new RuleAttempt($rule, $attempt);
            } elseif ($rule->mode === RuleMode::DryRun) {
                // Not counted, and not enforced (ADR-0012).
                $wouldBlock[] = new RuleAttempt($rule, $attempt);
            } else {
                [$settled, $releaseFailures] = $this->release($attempts);

                return Evaluation::rejected(new RuleAttempt($rule, $attempt), $settled, $failures, $wouldBlock, $skipped, $releaseFailures, $ignored, $exempted, $exemptionFailures);
            }
        }

        return $failures === []
            ? Evaluation::allowed($attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures)
            : Evaluation::failed($failures, $attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures);
    }

    /**
     * Releases every counted attempt, dry-run rules' included (ADR-0021). A count
     * that cannot be released stays, which is stricter, never looser, and the
     * request stays rejected.
     *
     * @param  list<RuleAttempt>  $attempts
     * @return array{list<RuleAttempt>, list<RuleFailure>} the attempts, marked if released, and the releases that failed
     */
    private function release(array $attempts): array
    {
        $settled = [];
        $releaseFailures = [];

        foreach ($attempts as $counted) {
            try {
                $this->store->release($counted->attempt);
                $settled[] = new RuleAttempt($counted->rule, $counted->attempt, released: true);
            } catch (StorageUnavailableException $exception) {
                $settled[] = $counted;
                $releaseFailures[] = new RuleFailure($counted->rule, $exception);
            }
        }

        return [$settled, $releaseFailures];
    }
}
