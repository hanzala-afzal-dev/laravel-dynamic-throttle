<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\OneCallPerAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\Plan;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleExemption;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

covers(RuleEvaluator::class, Plan::class);

/*
| SPEC-014 decision 8: the evaluator's planning and deciding steps, which
| dynamic-throttle:explain runs around the counters it reads, without
| counting anything. evaluate() is plan(), one store call, then decide().
*/

/**
 * @param  array<string, mixed>  $overrides
 */
function plannedRule(string $id, array $overrides = []): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule(['id' => $id, ...$overrides]));
}

/**
 * Each rule's client is a hash of the rule's id, except for the rules exempt, those
 * without an identity, and those whose identity fails, by rule id.
 *
 * @param  array<string, string>  $exempt  the exemption that frees the client, by rule id
 * @param  list<string>  $missing
 * @param  list<string>  $failing
 */
function planIdentities(array $exempt = [], array $missing = [], array $failing = []): IdentityResolver
{
    return new readonly class($exempt, $missing, $failing) implements IdentityResolver
    {
        /**
         * @param  array<string, string>  $exempt
         * @param  list<string>  $missing
         * @param  list<string>  $failing
         */
        public function __construct(private array $exempt, private array $missing, private array $failing) {}

        public function exemption(CompiledRule $rule): ?string
        {
            return $this->exempt[$rule->id] ?? null;
        }

        public function resolve(CompiledRule $rule): ?string
        {
            return match (true) {
                in_array($rule->id, $this->failing, true) => throw IdentityUnavailableException::because("The identity for rule {$rule->id} could not be resolved."),
                in_array($rule->id, $this->missing, true) => null,
                default => hash('sha256', "client of {$rule->id}"),
            };
        }
    };
}

/**
 * A store that fails the test when it is called: planning and deciding never count.
 */
function untouchedStore(): LimiterStore
{
    return new class implements LimiterStore
    {
        public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
        {
            throw new LogicException('The store was asked to count.');
        }

        public function release(Attempt $attempt): void
        {
            throw new LogicException('The store was asked to release.');
        }
    };
}

/**
 * @param  list<CompiledRule>  $rules
 * @return list<string>
 */
function planRuleIds(array $rules): array
{
    return array_map(fn (CompiledRule $rule): string => $rule->id, $rules);
}

/**
 * @param  array<int, RuleFailure>  $failures
 * @return list<array{string, string}>
 */
function planFailures(array $failures): array
{
    return array_map(fn (RuleFailure $failure): array => [$failure->rule->id, $failure->exception->getMessage()], array_values($failures));
}

it('SPEC-014: plan() plans the attempts evaluate() counts, under the scale factor, and says why the other rules count nothing, without the store', function (): void {
    $evaluator = new RuleEvaluator(untouchedStore(), maxRulesPerRequest: 1);
    $rules = [
        plannedRule('1'),
        plannedRule('2', ['on_missing_identity' => 'skip']),
        plannedRule('3'),
        plannedRule('4', ['max_attempts' => 5]),
        plannedRule('5', ['max_attempts' => 3, 'window_seconds' => 10, 'mode' => 'dry_run']),
        plannedRule('6'),
    ];

    $plan = $evaluator->plan($rules, planIdentities(exempt: ['1' => '9'], missing: ['2'], failing: ['3']), 200);

    expect(planRuleIds($plan->rules))->toBe(['4', '5'])
        ->and(array_map(fn (PlannedAttempt $attempt): array => [$attempt->key->ruleId, $attempt->key->identityHash, $attempt->maxAttempts, $attempt->windowSeconds, $attempt->enforces], $plan->attempts))->toBe([
            ['4', hash('sha256', 'client of 4'), 10, 60, true],
            ['5', hash('sha256', 'client of 5'), 6, 10, false],
        ])
        ->and(array_map(fn (RuleExemption $exempted): array => [$exempted->rule->id, $exempted->exemptionId], $plan->exempted))->toBe([['1', '9']])
        ->and(planRuleIds($plan->skipped))->toBe(['2'])
        ->and(planFailures($plan->failures))->toBe([['3', 'The identity for rule 3 could not be resolved.']])
        // Rules that count nothing take no slot, so rule 4 took the one enforcing slot.
        ->and(planRuleIds($plan->ignored))->toBe(['6'])
        ->and($plan->exemptionFailures)->toBe([])
        ->and($plan->stop)->toBeNull();
});

it('SPEC-014: plan() without a scale factor plans each rule\'s own limit', function (): void {
    $plan = (new RuleEvaluator(untouchedStore()))->plan([plannedRule('1', ['max_attempts' => 1000])], planIdentities());

    expect(array_map(fn (PlannedAttempt $attempt): int => $attempt->maxAttempts, $plan->attempts))->toBe([1000]);
});

it('SPEC-014: plan() ends at an enforcing fail-closed rule whose identity fails, as evaluate() stops there', function (): void {
    $plan = (new RuleEvaluator(untouchedStore()))->plan([
        plannedRule('1'),
        plannedRule('2', ['on_failure' => 'closed']),
        plannedRule('3'),
    ], planIdentities(failing: ['2']));

    expect(planRuleIds($plan->rules))->toBe(['1'])
        ->and($plan->stop?->id)->toBe('2')
        ->and(planFailures($plan->failures))->toBe([['2', 'The identity for rule 2 could not be resolved.']])
        ->and([$plan->skipped, $plan->ignored, $plan->exempted])->toBe([[], [], []]);
});

it('SPEC-014: plan() keeps the exemption checks that failed, which exempt nothing', function (): void {
    $identities = new readonly class implements IdentityResolver
    {
        public function exemption(CompiledRule $rule): ?string
        {
            throw IdentityUnavailableException::because('The exemptions could not be checked.');
        }

        public function resolve(CompiledRule $rule): string
        {
            return hash('sha256', 'client');
        }
    };

    $plan = (new RuleEvaluator(untouchedStore()))->plan([plannedRule('1')], $identities);

    expect(planRuleIds($plan->rules))->toBe(['1'])
        ->and(planFailures($plan->exemptionFailures))->toBe([['1', 'The exemptions could not be checked.']]);
});

it('SPEC-014: decide() gives the evaluation of the store\'s answers to the plan, as evaluate() does', function (): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore(new Repository(new ArrayStore), new KeyBuilder('dt:'), $clock);
    $evaluator = new RuleEvaluator($store);
    $rules = [plannedRule('1', ['max_attempts' => 10]), plannedRule('2', ['max_attempts' => 1, 'mode' => 'dry_run']), plannedRule('3', ['max_attempts' => 1])];
    $evaluator->evaluate($rules, planIdentities());

    $plan = $evaluator->plan($rules, planIdentities());
    $decided = $evaluator->decide($plan, (new OneCallPerAttempt($store))->attemptAll($plan->attempts, false));
    $evaluated = $evaluator->evaluate($rules, planIdentities());

    expect([$decided->outcome, $decided->rejection?->rule->id])->toBe([EvaluationOutcome::Rejected, '3'])
        ->and(array_map(fn (RuleAttempt $attempt): array => [$attempt->rule->id, $attempt->released], $decided->attempts))->toBe([['1', true]])
        ->and(array_map(fn (RuleAttempt $attempt): string => $attempt->rule->id, $decided->wouldBlock))->toBe(['2'])
        // The released count left the store as evaluate() leaves it, so evaluate() decides the same.
        ->and([$evaluated->outcome, $evaluated->rejection?->rule->id])->toBe([EvaluationOutcome::Rejected, '3']);
});

it('SPEC-014: decide() turns a failed store call into the failure policy\'s decision', function (): void {
    $evaluator = new RuleEvaluator(untouchedStore());
    $plan = $evaluator->plan([plannedRule('1'), plannedRule('2', ['on_failure' => 'closed'])], planIdentities());

    $evaluation = $evaluator->decide($plan, new AttemptBatch(failure: StorageUnavailableException::because('The store is down.')));

    expect([$evaluation->outcome, $evaluation->unavailable?->id])->toBe([EvaluationOutcome::Unavailable, '2'])
        ->and(planFailures($evaluation->failures))->toBe([['1', 'The store is down.']]);
});

it('SPEC-014: decide() leaves the planned rules unchecked without the store\'s answers, as during the cooldown', function (): void {
    $evaluator = new RuleEvaluator(untouchedStore());
    $plan = $evaluator->plan([plannedRule('1'), plannedRule('2', ['mode' => 'dry_run'])], planIdentities());

    $evaluation = $evaluator->decide($plan, new AttemptBatch);

    expect([$evaluation->outcome, planRuleIds($evaluation->unchecked)])->toBe([EvaluationOutcome::Allowed, ['1', '2']]);
});

it('SPEC-014: decide() gives the stop\'s 503 when no earlier rule rejects the request', function (): void {
    $evaluator = new RuleEvaluator(untouchedStore());
    $plan = $evaluator->plan([plannedRule('1', ['max_attempts' => 5]), plannedRule('2', ['on_failure' => 'closed'])], planIdentities(failing: ['2']));
    $allowed = new Attempt($plan->attempts[0]->key, 5, 60, 30_000_000, true, 4, 0);

    $evaluation = $evaluator->decide($plan, new AttemptBatch([$allowed], [true]));

    expect([$evaluation->outcome, $evaluation->unavailable?->id])->toBe([EvaluationOutcome::Unavailable, '2'])
        ->and(array_map(fn (RuleAttempt $attempt): array => [$attempt->rule->id, $attempt->released], $evaluation->attempts))->toBe([['1', true]]);
});
