<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

covers(RuleEvaluator::class, Evaluation::class, RuleAttempt::class, RuleFailure::class);

/*
| SPEC-007 AC-9 to AC-16: stacking (ADR-0008). The cache driver on the array
| store, the clock frozen at T0+10, window 60 s, one client.
*/

function stackRule(string $id, int $max, int $priority, RuleMode $mode = RuleMode::Enforce): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule([
        'id' => $id,
        'max_attempts' => $max,
        'priority' => $priority,
        'mode' => $mode->value,
    ]));
}

/**
 * @return object{store: CacheLimiterStore, cache: Repository}
 */
function stackStore(): object
{
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $cache = new Repository(new ArrayStore);

    return (object) ['store' => new CacheLimiterStore($cache, new KeyBuilder('dt:'), $clock), 'cache' => $cache];
}

/**
 * One client for every rule, except the rules listed as not applying.
 *
 * @param  list<string>  $notApplying
 */
function oneClient(array $notApplying = []): IdentityResolver
{
    return new readonly class($notApplying) implements IdentityResolver
    {
        /**
         * @param  list<string>  $notApplying
         */
        public function __construct(private array $notApplying) {}

        public function resolve(CompiledRule $rule): ?string
        {
            return in_array($rule->id, $this->notApplying, true) ? null : hash('sha256', 'client');
        }
    };
}

/**
 * The client's stored count for a rule, in the window of T0+10.
 */
function stackCount(Repository $cache, string $ruleId): int
{
    $count = $cache->get((new KeyBuilder('dt:'))->counter(new CounterKey($ruleId, hash('sha256', 'client')), 60, 30_000_000));

    return is_numeric($count) ? (int) $count : 0;
}

/**
 * A store that fails attempts, or releases, for one rule.
 */
function failingFor(LimiterStore $inner, ?string $attemptsOf = null, ?string $releasesOf = null): LimiterStore
{
    return new readonly class($inner, $attemptsOf, $releasesOf) implements LimiterStore
    {
        public function __construct(
            private LimiterStore $inner,
            private ?string $attemptsOf,
            private ?string $releasesOf,
        ) {}

        public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
        {
            return $key->ruleId === $this->attemptsOf
                ? throw StorageUnavailableException::because('The store is down.')
                : $this->inner->attempt($key, $maxAttempts, $windowSeconds);
        }

        public function release(Attempt $attempt): void
        {
            $attempt->key->ruleId === $this->releasesOf
                ? throw StorageUnavailableException::because('The store is down.')
                : $this->inner->release($attempt);
        }
    };
}

/**
 * @param  list<RuleAttempt>  $attempts
 * @return list<array{string, bool}>
 */
function attemptSummary(array $attempts): array
{
    return array_map(fn (RuleAttempt $attempt): array => [$attempt->rule->id, $attempt->released], $attempts);
}

/**
 * @param  list<RuleFailure>  $failures
 * @return list<array{string, string}>
 */
function failureSummary(array $failures): array
{
    return array_map(fn (RuleFailure $failure): array => [$failure->rule->id, $failure->exception->getMessage()], $failures);
}

/**
 * One client for every rule, except the rules whose identity cannot be resolved.
 *
 * @param  list<string>  $unresolvable
 */
function failingIdentities(array $unresolvable): IdentityResolver
{
    return new readonly class($unresolvable) implements IdentityResolver
    {
        /**
         * @param  list<string>  $unresolvable
         */
        public function __construct(private array $unresolvable) {}

        public function resolve(CompiledRule $rule): string
        {
            return in_array($rule->id, $this->unresolvable, true)
                ? throw IdentityUnavailableException::because('The identity for custom:plan could not be resolved.')
                : hash('sha256', 'client');
        }
    };
}

it('AC-9: counts an allowed request on every matched rule, in order', function (): void {
    $stack = stackStore();

    $evaluation = (new RuleEvaluator($stack->store))->evaluate([stackRule('a', 5, 10), stackRule('b', 5, 20)], oneClient());

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false], ['b', false]])
        ->and([$evaluation->rejection, $evaluation->failures, $evaluation->wouldBlock, $evaluation->skipped])->toBe([null, [], [], []])
        ->and([stackCount($stack->cache, 'a'), stackCount($stack->cache, 'b')])->toBe([1, 1]);
});

it('AC-10: releases the lenient rule when the stricter one rejects', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator($stack->store);
    $rules = [stackRule('lenient', 100, 10), stackRule('strict', 5, 20)];

    $evaluations = array_map(fn (): Evaluation => $evaluator->evaluate($rules, oneClient()), range(1, 7));
    $sixth = $evaluations[5];

    expect(array_map(fn (Evaluation $evaluation): EvaluationOutcome => $evaluation->outcome, array_slice($evaluations, 0, 5)))
        ->toBe(array_fill(0, 5, EvaluationOutcome::Allowed))
        ->and($sixth->outcome)->toBe(EvaluationOutcome::Rejected)
        ->and($sixth->rejection?->rule->id)->toBe('strict')
        ->and(attemptSummary($sixth->attempts))->toBe([['lenient', true]])
        ->and($evaluations[6]->attempts[0]->attempt->remaining)->toBe(94)
        ->and(stackCount($stack->cache, 'lenient'))->toBe(5);
});

it('AC-11: stops at the first enforcing rejection', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator($stack->store);
    $rules = [stackRule('a', 1, 10), stackRule('b', 100, 20)];

    $evaluator->evaluate($rules, oneClient());
    $second = $evaluator->evaluate($rules, oneClient());

    expect($second->outcome)->toBe(EvaluationOutcome::Rejected)
        ->and($second->rejection?->rule->id)->toBe('a')
        ->and($second->attempts)->toBe([])
        ->and(stackCount($stack->cache, 'b'))->toBe(1);
});

it('AC-12: never rejects for a dry-run rule, and records that it would have', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator($stack->store);
    $rules = [stackRule('d', 1, 10, RuleMode::DryRun), stackRule('e', 100, 20)];

    $evaluator->evaluate($rules, oneClient());
    $second = $evaluator->evaluate($rules, oneClient());

    expect($second->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and(array_map(fn (RuleAttempt $attempt): string => $attempt->rule->id, $second->wouldBlock))->toBe(['d'])
        ->and(attemptSummary($second->attempts))->toBe([['e', false]])
        ->and([stackCount($stack->cache, 'd'), stackCount($stack->cache, 'e')])->toBe([1, 2]);
});

it('AC-13: releases dry-run rules like enforcing ones (ADR-0021)', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator($stack->store);
    $rules = [stackRule('d', 100, 10, RuleMode::DryRun), stackRule('s', 1, 20)];

    $evaluator->evaluate($rules, oneClient());
    $second = $evaluator->evaluate($rules, oneClient());

    expect($second->outcome)->toBe(EvaluationOutcome::Rejected)
        ->and($second->rejection?->rule->id)->toBe('s')
        ->and(attemptSummary($second->attempts))->toBe([['d', true]])
        ->and(stackCount($stack->cache, 'd'))->toBe(1);
});

it('AC-14: skips a rule whose identity resolves to null, and goes on to the next', function (): void {
    $stack = stackStore();
    $rules = [stackRule('a', 5, 10), stackRule('b', 5, 20), stackRule('c', 5, 30)];

    $evaluation = (new RuleEvaluator($stack->store))->evaluate($rules, oneClient(['b']));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false], ['c', false]])
        ->and(array_map(fn (CompiledRule $rule): string => $rule->id, $evaluation->skipped))->toBe(['b'])
        ->and([stackCount($stack->cache, 'b'), stackCount($stack->cache, 'c')])->toBe([0, 1]);
});

it('AC-12 and AC-14: keeps the would-block and skipped rules when a later rule rejects, or fails', function (): void {
    $stack = stackStore();
    $rules = [stackRule('d', 1, 10, RuleMode::DryRun), stackRule('k', 5, 20), stackRule('s', 1, 30)];
    $evaluator = new RuleEvaluator($stack->store);
    $ids = fn (array $items): array => array_map(
        fn (RuleAttempt|CompiledRule $item): string => $item instanceof RuleAttempt ? $item->rule->id : $item->id,
        $items,
    );

    $evaluator->evaluate($rules, oneClient(['k']));
    $rejected = $evaluator->evaluate($rules, oneClient(['k']));
    $failed = (new RuleEvaluator(failingFor($stack->store, attemptsOf: 's')))->evaluate($rules, oneClient(['k']));

    expect([$rejected->outcome, $ids($rejected->wouldBlock), $ids($rejected->skipped)])->toBe([EvaluationOutcome::Rejected, ['d'], ['k']])
        ->and([$failed->outcome, $ids($failed->wouldBlock), $ids($failed->skipped)])->toBe([EvaluationOutcome::Failed, ['d'], ['k']]);
});

it('AC-15: keeps the rejection when a release fails, and still releases the others', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator(failingFor($stack->store, releasesOf: 'a'));
    $rules = [stackRule('a', 100, 10), stackRule('b', 100, 20), stackRule('s', 1, 30)];

    $evaluator->evaluate($rules, oneClient());
    $second = $evaluator->evaluate($rules, oneClient());

    expect($second->outcome)->toBe(EvaluationOutcome::Rejected)
        ->and($second->rejection?->rule->id)->toBe('s')
        ->and(attemptSummary($second->attempts))->toBe([['a', false], ['b', true]])
        ->and(array_map(fn (RuleFailure $failure): string => $failure->rule->id, $second->releaseFailures))->toBe(['a'])
        ->and($second->releaseFailures[0]->exception->getMessage())->toBe('The store is down.')
        ->and([stackCount($stack->cache, 'a'), stackCount($stack->cache, 'b')])->toBe([2, 1]);
});

it('AC-16: stops with a Failed outcome when an attempt fails, releasing nothing and throwing nothing', function (): void {
    $stack = stackStore();

    $evaluation = (new RuleEvaluator(failingFor($stack->store, attemptsOf: 'b')))
        ->evaluate([stackRule('a', 100, 10), stackRule('b', 100, 20), stackRule('c', 100, 30)], oneClient());

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and(failureSummary($evaluation->failures))->toBe([['b', 'The store is down.']])
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false]])
        ->and([stackCount($stack->cache, 'a'), stackCount($stack->cache, 'c')])->toBe([1, 0]);
});

/*
| SPEC-008 AC-12 and AC-19 (decision 6): an identity that cannot be resolved
| fails only its own rule, and evaluation goes on to the next one.
*/

it('SPEC-008 AC-12: fails only the rule whose identity cannot be resolved, and the rules after it still count', function (): void {
    $stack = stackStore();

    $evaluation = (new RuleEvaluator($stack->store))
        ->evaluate([stackRule('a', 100, 10), stackRule('b', 100, 20), stackRule('c', 100, 30)], failingIdentities(['b']));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and(failureSummary($evaluation->failures))->toBe([['b', 'The identity for custom:plan could not be resolved.']])
        ->and($evaluation->failures[0]->exception)->toBeInstanceOf(IdentityUnavailableException::class)
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false], ['c', false]])
        ->and([stackCount($stack->cache, 'a'), stackCount($stack->cache, 'b'), stackCount($stack->cache, 'c')])->toBe([1, 0, 1]);
});

it('SPEC-008 AC-19: still rejects on an enforcing rule after a failed identity, and lists the failure', function (): void {
    $stack = stackStore();
    $evaluator = new RuleEvaluator($stack->store);
    $rules = [stackRule('a', 100, 10), stackRule('b', 100, 20), stackRule('s', 1, 30)];

    $evaluator->evaluate($rules, failingIdentities(['b']));
    $second = $evaluator->evaluate($rules, failingIdentities(['b']));

    expect($second->outcome)->toBe(EvaluationOutcome::Rejected)
        ->and($second->rejection?->rule->id)->toBe('s')
        ->and(attemptSummary($second->attempts))->toBe([['a', true]])
        ->and(failureSummary($second->failures))->toBe([['b', 'The identity for custom:plan could not be resolved.']])
        ->and(stackCount($stack->cache, 'a'))->toBe(1);
});

it('SPEC-008 AC-19: stops at a failed attempt after a failed identity, and lists both failures in order', function (): void {
    $stack = stackStore();
    $rules = [stackRule('a', 100, 10), stackRule('b', 100, 20), stackRule('c', 100, 30), stackRule('d', 100, 40)];

    $evaluation = (new RuleEvaluator(failingFor($stack->store, attemptsOf: 'c')))->evaluate($rules, failingIdentities(['b']));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Failed)
        ->and(failureSummary($evaluation->failures))->toBe([
            ['b', 'The identity for custom:plan could not be resolved.'],
            ['c', 'The store is down.'],
        ])
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false]])
        ->and(stackCount($stack->cache, 'd'))->toBe(0);
});

it('SPEC-008 AC-19: gives a rule whose identity failed no slot, because it made no attempt (ADR-0025)', function (): void {
    $stack = stackStore();

    $evaluation = (new RuleEvaluator($stack->store, 1))
        ->evaluate([stackRule('b', 100, 10), stackRule('c', 100, 20)], failingIdentities(['b']));

    expect(attemptSummary($evaluation->attempts))->toBe([['c', false]])
        ->and($evaluation->ignored)->toBe([])
        ->and(failureSummary($evaluation->failures))->toBe([['b', 'The identity for custom:plan could not be resolved.']]);
});

/*
| ADR-0025: the cap counts attempts on the store, per mode. The rules go through
| the matcher first, as a request's do, and all match "api/x".
*/

/**
 * @param  list<CompiledRule>  $rules  in priority order
 * @return list<CompiledRule>
 */
function matchedForCap(array $rules): array
{
    return (new RuleMatcher)->match(new Manifest(1, $rules), 'GET', 'api/x')->rules;
}

/**
 * @param  list<CompiledRule>  $rules
 * @return list<string>
 */
function ruleIds(array $rules): array
{
    return array_map(fn (CompiledRule $rule): string => $rule->id, $rules);
}

it('ADR-0025: lets a guest rule count behind five rules that skip guests', function (): void {
    $stack = stackStore();
    $skipping = array_map(fn (int $n): CompiledRule => stackRule("skip{$n}", 100, $n), range(1, 5));
    $rules = matchedForCap([...$skipping, stackRule('guests', 1, 100)]);
    $evaluator = new RuleEvaluator($stack->store, 5);
    $guest = oneClient(['skip1', 'skip2', 'skip3', 'skip4', 'skip5']);

    $first = $evaluator->evaluate($rules, $guest);
    $second = $evaluator->evaluate($rules, $guest);

    expect([$first->outcome, $second->outcome])->toBe([EvaluationOutcome::Allowed, EvaluationOutcome::Rejected])
        ->and($second->rejection?->rule->id)->toBe('guests')
        ->and(ruleIds($first->skipped))->toBe(['skip1', 'skip2', 'skip3', 'skip4', 'skip5']);
});

it('ADR-0025: gives dry-run rules slots of their own, so they never switch off an enforcing rule', function (): void {
    $stack = stackStore();
    $enforcing = array_map(fn (int $n): CompiledRule => stackRule("e{$n}", 100, $n + 1), range(1, 5));
    $rules = matchedForCap([stackRule('d', 100, 1, RuleMode::DryRun), ...$enforcing]);

    $evaluation = (new RuleEvaluator($stack->store, 5))->evaluate($rules, oneClient());

    expect(attemptSummary($evaluation->attempts))->toBe([['d', false], ['e1', false], ['e2', false], ['e3', false], ['e4', false], ['e5', false]])
        ->and($evaluation->ignored)->toBe([]);
});

it('ADR-0025: counts at most five enforcing rules, and lists the rest as ignored', function (): void {
    $stack = stackStore();
    $rules = matchedForCap(array_map(fn (int $n): CompiledRule => stackRule("e{$n}", 100, $n), range(1, 7)));

    $evaluation = (new RuleEvaluator($stack->store, 5))->evaluate($rules, oneClient());

    expect(ruleIds(array_map(fn (RuleAttempt $attempt): CompiledRule => $attempt->rule, $evaluation->attempts)))->toBe(['e1', 'e2', 'e3', 'e4', 'e5'])
        ->and(ruleIds($evaluation->ignored))->toBe(['e6', 'e7'])
        ->and([stackCount($stack->cache, 'e5'), stackCount($stack->cache, 'e6')])->toBe([1, 0]);
});

it('ADR-0025: counts at most five dry-run rules on top, and the enforcing rule after them', function (): void {
    $stack = stackStore();
    $dryRun = array_map(fn (int $n): CompiledRule => stackRule("d{$n}", 100, $n, RuleMode::DryRun), range(1, 7));
    $rules = matchedForCap([...$dryRun, stackRule('e', 100, 8)]);

    $evaluation = (new RuleEvaluator($stack->store, 5))->evaluate($rules, oneClient());

    expect(ruleIds(array_map(fn (RuleAttempt $attempt): CompiledRule => $attempt->rule, $evaluation->attempts)))->toBe(['d1', 'd2', 'd3', 'd4', 'd5', 'e'])
        ->and(ruleIds($evaluation->ignored))->toBe(['d6', 'd7']);
});

it('ADR-0025: allows five rules per mode by default, as matching.max_rules_per_request does', function (): void {
    $stack = stackStore();
    $rules = matchedForCap(array_map(fn (int $n): CompiledRule => stackRule("e{$n}", 100, $n), range(1, 7)));

    $evaluation = (new RuleEvaluator($stack->store))->evaluate($rules, oneClient());

    expect([count($evaluation->attempts), ruleIds($evaluation->ignored)])->toBe([5, ['e6', 'e7']]);
});

it('ADR-0025: never resolves the identity of an ignored rule', function (): void {
    $stack = stackStore();
    $resolved = [];
    $identities = new class($resolved) implements IdentityResolver
    {
        /**
         * @param  list<string>  $resolved
         */
        public function __construct(private array &$resolved) {}

        public function resolve(CompiledRule $rule): string
        {
            $this->resolved[] = $rule->id;

            return hash('sha256', 'client');
        }
    };
    $rules = matchedForCap(array_map(fn (int $n): CompiledRule => stackRule("e{$n}", 100, $n), range(1, 7)));

    (new RuleEvaluator($stack->store, 5))->evaluate($rules, $identities);

    expect($resolved)->toBe(['e1', 'e2', 'e3', 'e4', 'e5']);
});
