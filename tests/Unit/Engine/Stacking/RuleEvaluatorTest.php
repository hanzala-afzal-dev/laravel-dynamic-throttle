<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
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

it('AC-9: counts an allowed request on every matched rule, in order', function (): void {
    $stack = stackStore();

    $evaluation = (new RuleEvaluator($stack->store))->evaluate([stackRule('a', 5, 10), stackRule('b', 5, 20)], oneClient());

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false], ['b', false]])
        ->and([$evaluation->rejection, $evaluation->failure, $evaluation->wouldBlock, $evaluation->skipped])->toBe([null, null, [], []])
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
        ->and($evaluation->failure?->rule->id)->toBe('b')
        ->and($evaluation->failure?->exception->getMessage())->toBe('The store is down.')
        ->and(attemptSummary($evaluation->attempts))->toBe([['a', false]])
        ->and([stackCount($stack->cache, 'a'), stackCount($stack->cache, 'c')])->toBe([1, 0]);
});
