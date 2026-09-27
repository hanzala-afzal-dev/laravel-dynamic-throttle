<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * Counts a request on each of its matched rules, in order (ADR-0008, SPEC-007).
 * The first enforcing rule that rejects it stops evaluation, and every count
 * made so far is released, so a rejected request counts nowhere. A rule the
 * client is exempt from counts nothing (SPEC-009).
 *
 * Once the store fails, or while its cooldown runs, no rule tries it, and the
 * first fail-closed rule that applies stops evaluation (SPEC-012).
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
        /** Keeps the store out of the next requests once it fails (ADR-0010 point 4). Without one, each request tries it. */
        private ?Cooldown $cooldown = null,
    ) {}

    /**
     * Never throws a StorageUnavailableException or an IdentityUnavailableException.
     * An identity that cannot be resolved fails only its own rule (SPEC-008), a
     * failed attempt keeps the later rules off the store, and a failed release is
     * listed (ADR-0021). So is a failed exemption check, which exempts nothing
     * (SPEC-009). A fail-closed rule that cannot be checked stops evaluation with
     * the Unavailable outcome (SPEC-012).
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
        $unchecked = [];
        // Attempts left for each mode: a rule that does not apply takes none, and a
        // dry-run rule never takes an enforcing rule's (ADR-0025).
        $slots = [RuleMode::Enforce->value => $this->maxRulesPerRequest, RuleMode::DryRun->value => $this->maxRulesPerRequest];
        // During the cooldown no rule tries the store, so an outage costs one failed call per cooldown.
        $storeDown = $this->cooldown instanceof Cooldown && $this->cooldown->secondsLeft() > 0;

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

                if (! $this->failsClosed($rule)) {
                    continue;
                }

                // As at a rejection, the request counts nowhere. Releases on a store that is down would only fail again.
                [$settled, $releaseFailures] = $storeDown ? [$attempts, []] : $this->release($attempts);

                return Evaluation::unavailable($rule, $settled, $failures, $wouldBlock, $skipped, $releaseFailures, $ignored, $exempted, $exemptionFailures, $unchecked);
            }

            if ($identity === null) {
                $skipped[] = $rule;

                continue;
            }

            // Taken before the attempt: a rule the store cannot count still applies, so
            // which rules apply never depends on the store's health.
            $slots[$rule->mode->value]--;

            if ($storeDown) {
                if ($this->failsClosed($rule)) {
                    return Evaluation::unavailable($rule, $attempts, $failures, $wouldBlock, $skipped, [], $ignored, $exempted, $exemptionFailures, $unchecked);
                }

                $unchecked[] = $rule;

                continue;
            }

            try {
                $attempt = $this->store->attempt(new CounterKey($rule->id, $identity), $rule->maxAttempts, $rule->windowSeconds);
            } catch (StorageUnavailableException $exception) {
                $failures[] = new RuleFailure($rule, $exception);
                // The later rules count on the same store, so they would fail too. The counts
                // made so far stay: releasing them would only fail again, and a kept count is
                // stricter, never looser (SPEC-004).
                $storeDown = true;
                $this->cooldown?->start();

                if ($this->failsClosed($rule)) {
                    return Evaluation::unavailable($rule, $attempts, $failures, $wouldBlock, $skipped, [], $ignored, $exempted, $exemptionFailures, $unchecked);
                }

                continue;
            }

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
            ? Evaluation::allowed($attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures, $unchecked)
            : Evaluation::failed($failures, $attempts, $wouldBlock, $skipped, $ignored, $exempted, $exemptionFailures, $unchecked);
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
     * Releases every counted attempt, dry-run rules' included (ADR-0021). A count
     * that cannot be released stays, which is stricter, never looser, and the
     * request stays rejected. A failed release starts the cooldown, and the other
     * releases are still tried: the store just counted for this request.
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
                $this->cooldown?->start();
            }
        }

        return [$settled, $releaseFailures];
    }
}
