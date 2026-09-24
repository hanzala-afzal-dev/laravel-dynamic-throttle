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
 * made so far is released, so a rejected request counts nowhere.
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
     * Never throws a StorageUnavailableException or an IdentityUnavailableException:
     * a failed attempt, or an identity that cannot be resolved, ends with a Failed
     * outcome (SPEC-008), and a failed release is listed (ADR-0021).
     *
     * @param  list<CompiledRule>  $rules  the matched rules, in manifest order
     */
    public function evaluate(array $rules, IdentityResolver $identities): Evaluation
    {
        $attempts = [];
        $wouldBlock = [];
        $skipped = [];
        $ignored = [];
        // Attempts left for each mode: a rule that does not apply takes none, and a
        // dry-run rule never takes an enforcing rule's (ADR-0025).
        $slots = [RuleMode::Enforce->value => $this->maxRulesPerRequest, RuleMode::DryRun->value => $this->maxRulesPerRequest];

        foreach ($rules as $rule) {
            if ($slots[$rule->mode->value] === 0) {
                $ignored[] = $rule;

                continue;
            }

            try {
                $identity = $identities->resolve($rule);
            } catch (IdentityUnavailableException $exception) {
                return Evaluation::failed(new RuleFailure($rule, $exception), $attempts, $wouldBlock, $skipped, $ignored);
            }

            if ($identity === null) {
                $skipped[] = $rule;

                continue;
            }

            try {
                $attempt = $this->store->attempt(new CounterKey($rule->id, $identity), $rule->maxAttempts, $rule->windowSeconds);
            } catch (StorageUnavailableException $exception) {
                return Evaluation::failed(new RuleFailure($rule, $exception), $attempts, $wouldBlock, $skipped, $ignored);
            }

            $slots[$rule->mode->value]--;

            if ($attempt->allowed) {
                $attempts[] = new RuleAttempt($rule, $attempt);
            } elseif ($rule->mode === RuleMode::DryRun) {
                // Not counted, and not enforced (ADR-0012).
                $wouldBlock[] = new RuleAttempt($rule, $attempt);
            } else {
                return $this->reject(new RuleAttempt($rule, $attempt), $attempts, $wouldBlock, $skipped, $ignored);
            }
        }

        return Evaluation::allowed($attempts, $wouldBlock, $skipped, $ignored);
    }

    /**
     * Releases every counted attempt, dry-run rules' included (ADR-0021). A count
     * that cannot be released stays, which is stricter, never looser, and the
     * request stays rejected.
     *
     * @param  list<RuleAttempt>  $attempts
     * @param  list<RuleAttempt>  $wouldBlock
     * @param  list<CompiledRule>  $skipped
     * @param  list<CompiledRule>  $ignored
     */
    private function reject(RuleAttempt $rejection, array $attempts, array $wouldBlock, array $skipped, array $ignored): Evaluation
    {
        $settled = [];
        $failures = [];

        foreach ($attempts as $counted) {
            try {
                $this->store->release($counted->attempt);
                $settled[] = new RuleAttempt($counted->rule, $counted->attempt, released: true);
            } catch (StorageUnavailableException $exception) {
                $settled[] = $counted;
                $failures[] = new RuleFailure($counted->rule, $exception);
            }
        }

        return Evaluation::rejected($rejection, $settled, $wouldBlock, $skipped, $failures, $ignored);
    }
}
