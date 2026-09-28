<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\BatchLimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * Counts a request on its matched rules (ADR-0008, SPEC-007) in three steps
 * (ADR-0026, SPEC-027):
 * 1. Plans, without the store: the cap, the exemptions and each rule's identity,
 *    for every rule, in order. A rule the client is exempt from counts nothing
 *    (SPEC-009).
 * 2. Counts the plan in one store call. The first enforcing rule that rejects the
 *    request stops it, and every count made so far is released, so a rejected
 *    request counts nowhere.
 * 3. Decides, in evaluation order.
 *
 * Once the store fails, or while its cooldown runs, no rule is checked, and the
 * first fail-closed rule that applies stops evaluation (SPEC-012).
 */
final readonly class RuleEvaluator
{
    /** The store, or one call per attempt on a store that cannot count a request in one call. */
    private BatchLimiterStore $counter;

    public function __construct(
        LimiterStore $store,
        /**
         * matching.max_rules_per_request: store attempts per request for enforcing
         * rules, and as many again for dry-run rules (ADR-0025).
         */
        private int $maxRulesPerRequest = 5,
        /** Keeps the store out of the next requests once it fails (ADR-0010 point 4). Without one, each request tries it. */
        private ?Cooldown $cooldown = null,
    ) {
        $this->counter = $store instanceof BatchLimiterStore ? $store : new OneCallPerAttempt($store);
    }

    /**
     * Never throws a StorageUnavailableException or an IdentityUnavailableException.
     * An identity that cannot be resolved fails only its own rule (SPEC-008), a
     * failed store call leaves the rules it did not answer unchecked, and a failed
     * release is listed (ADR-0021). So is a failed exemption check, which exempts
     * nothing (SPEC-009). A fail-closed rule that cannot be checked stops
     * evaluation with the Unavailable outcome (SPEC-012), unless an earlier rule
     * rejects the request.
     *
     * @param  list<CompiledRule>  $rules  the matched rules, in manifest order
     */
    public function evaluate(array $rules, IdentityResolver $identities): Evaluation
    {
        // By position in $rules, so a store failure lands between the identity failures in evaluation order.
        $failures = [];
        $skipped = [];
        $ignored = [];
        $exempted = [];
        $exemptionFailures = [];
        // The rules that apply, and the attempts they plan, in the same order.
        $planned = [];
        $plan = [];
        // An enforcing fail-closed rule whose identity cannot be resolved ends the plan (SPEC-012).
        $stop = null;
        // Attempts left for each mode: a rule that does not apply takes none, and a
        // dry-run rule never takes an enforcing rule's (ADR-0025).
        $slots = [RuleMode::Enforce->value => $this->maxRulesPerRequest, RuleMode::DryRun->value => $this->maxRulesPerRequest];

        // 1. Plan. Every rule's identity is resolved before the store answers, even a
        // rule's after the one that will reject: counting them one call at a time
        // would cost a round trip per rule again (ADR-0026).
        foreach ($rules as $position => $rule) {
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
                $failures[$position] = new RuleFailure($rule, $exception);

                if ($this->failsClosed($rule)) {
                    $stop = $rule;

                    break;
                }

                continue;
            }

            if ($identity === null) {
                $skipped[] = $rule;

                continue;
            }

            // Taken before the attempt: a rule the store cannot count still applies, so
            // which rules apply never depends on the store's health.
            $slots[$rule->mode->value]--;
            $planned[] = [$position, $rule];
            $plan[] = new PlannedAttempt(new CounterKey($rule->id, $identity), $rule->maxAttempts, $rule->windowSeconds, $rule->mode === RuleMode::Enforce);
        }

        // 2. Count, unless the store is in its cooldown. With a stop, the call only asks
        // whether an earlier rule rejects, so it releases every count either way.
        $batch = $plan === [] || $this->inCooldown() ? new AttemptBatch : $this->counter->attemptAll($plan, $stop instanceof CompiledRule);

        if ($batch->failure instanceof StorageUnavailableException || $batch->releaseFailures !== []) {
            $this->cooldown?->start();
        }

        // 3. Decide, in evaluation order: the first enforcing rejection gives the 429, then
        // the first fail-closed rule the store could not check, then the stop.
        $attempts = [];
        $wouldBlock = [];
        $unchecked = [];
        $releaseFailures = [];
        $made = count($batch->attempts);

        foreach ($planned as $index => [$position, $rule]) {
            if ($index < $made) {
                $attempt = $batch->attempts[$index];

                if ($attempt->allowed) {
                    $attempts[] = new RuleAttempt($rule, $attempt, released: $batch->released[$index] ?? false);

                    // A count that cannot be released stays, which is stricter, never looser.
                    if (isset($batch->releaseFailures[$index])) {
                        $releaseFailures[] = new RuleFailure($rule, $batch->releaseFailures[$index]);
                    }
                } elseif ($rule->mode === RuleMode::DryRun) {
                    // Not counted, and not enforced (ADR-0012).
                    $wouldBlock[] = new RuleAttempt($rule, $attempt);
                } else {
                    return Evaluation::rejected(new RuleAttempt($rule, $attempt), $attempts, $this->inOrder($failures), $wouldBlock, $skipped, $releaseFailures, $ignored, $exempted, $exemptionFailures);
                }

                continue;
            }

            // Not counted: the store failed on this rule's attempt or an earlier one, or is in
            // its cooldown. The counts made before a failure stay: releasing them would only
            // fail again, and a kept count is stricter, never looser (SPEC-004).
            $failed = $index === $made && $batch->failure instanceof StorageUnavailableException;

            if ($failed) {
                $failures[$position] = new RuleFailure($rule, $batch->failure);
            }

            if ($this->failsClosed($rule)) {
                return Evaluation::unavailable($rule, $attempts, $this->inOrder($failures), $wouldBlock, $skipped, [], $ignored, $exempted, $exemptionFailures, $unchecked);
            }

            // The later rules count on the same store, so they go unchecked too.
            if (! $failed) {
                $unchecked[] = $rule;
            }
        }

        if ($stop instanceof CompiledRule) {
            // As at a rejection, the request counts nowhere: the call released every count.
            return Evaluation::unavailable($stop, $attempts, $this->inOrder($failures), $wouldBlock, $skipped, $releaseFailures, $ignored, $exempted, $exemptionFailures, $unchecked);
        }

        return $failures === []
            ? Evaluation::allowed($attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures, $unchecked)
            : Evaluation::failed($this->inOrder($failures), $attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures, $unchecked);
    }

    /**
     * While the cooldown runs, the store is skipped, so an outage costs one failed call per cooldown.
     */
    private function inCooldown(): bool
    {
        return $this->cooldown instanceof Cooldown && $this->cooldown->secondsLeft() > 0;
    }

    /**
     * An enforcing rule marked on_failure: closed answers 503 when it cannot be
     * checked (ADR-0010 point 2). A dry-run rule never changes a response (ADR-0012).
     */
    private function failsClosed(CompiledRule $rule): bool
    {
        return $rule->mode === RuleMode::Enforce && $rule->onFailure === FailurePolicy::Closed;
    }

    /**
     * @param  array<int, RuleFailure>  $failures  by the rule's position in evaluation order
     * @return ($failures is non-empty-array ? non-empty-list<RuleFailure> : list<RuleFailure>)
     */
    private function inOrder(array $failures): array
    {
        ksort($failures);

        return array_values($failures);
    }
}
