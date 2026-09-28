<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\OneCallPerAttempt;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;

covers(OneCallPerAttempt::class, AttemptBatch::class, PlannedAttempt::class);

/*
| SPEC-027 AC-3 to AC-5 and AC-7: the batch's behavior with one call per
| attempt and per release, for the cache driver, Redis Cluster and any other
| LimiterStore. The array store, the clock at T0 + 10, window 60 s, one client.
*/

/**
 * @return object{store: CacheLimiterStore, cache: Repository}
 */
function perAttemptStore(): object
{
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $cache = new Repository(new ArrayStore);

    return (object) ['store' => new CacheLimiterStore($cache, new KeyBuilder('dt:'), $clock), 'cache' => $cache];
}

function perAttemptPlan(string $rule, int $max, bool $enforces = true): PlannedAttempt
{
    return new PlannedAttempt(new CounterKey($rule, hash('sha256', 'client')), $max, 60, $enforces);
}

/**
 * Counts the client once on a rule, so a limit of 1 rejects the next attempt.
 */
function perAttemptAtLimit(LimiterStore $store, string $rule): void
{
    $store->attempt(perAttemptPlan($rule, 1)->key, 1, 60);
}

/**
 * The client's stored count for a rule, in the window of T0 + 10.
 */
function perAttemptCount(Repository $cache, string $rule): int
{
    $count = $cache->get((new KeyBuilder('dt:'))->counter(new CounterKey($rule, hash('sha256', 'client')), 60, 30_000_000));

    return is_numeric($count) ? (int) $count : 0;
}

/**
 * @return list<array{string, bool, bool}> each attempt's rule, whether it was allowed, and whether its count was released
 */
function perAttemptSummary(AttemptBatch $batch): array
{
    return array_map(
        fn (Attempt $attempt, bool $released): array => [$attempt->key->ruleId, $attempt->allowed, $released],
        $batch->attempts,
        $batch->released,
    );
}

/**
 * Counts the calls to a store, and fails attempts or releases for one rule.
 *
 * @return LimiterStore&object{attempts: int, releases: int}
 */
function perAttemptRecording(LimiterStore $inner, ?string $attemptsOf = null, ?string $releasesOf = null): LimiterStore
{
    return new class($inner, $attemptsOf, $releasesOf) implements LimiterStore
    {
        public int $attempts = 0;

        public int $releases = 0;

        public function __construct(
            private readonly LimiterStore $inner,
            private readonly ?string $attemptsOf,
            private readonly ?string $releasesOf,
        ) {}

        public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
        {
            $this->attempts++;

            return $key->ruleId === $this->attemptsOf
                ? throw StorageUnavailableException::because('The store is down.')
                : $this->inner->attempt($key, $maxAttempts, $windowSeconds);
        }

        public function release(Attempt $attempt): void
        {
            $this->releases++;

            $attempt->key->ruleId === $this->releasesOf
                ? throw StorageUnavailableException::because('The store could not release.')
                : $this->inner->release($attempt);
        }
    };
}

it('AC-3: stops at the first enforcing rejection, releases every count made before it, and never counts after it', function (): void {
    $stack = perAttemptStore();
    perAttemptAtLimit($stack->store, 's');

    $batch = (new OneCallPerAttempt($stack->store))->attemptAll([
        perAttemptPlan('a', 100), perAttemptPlan('b', 100), perAttemptPlan('s', 1), perAttemptPlan('t', 100),
    ], false);

    expect(perAttemptSummary($batch))->toBe([['a', true, true], ['b', true, true], ['s', false, false]])
        ->and([perAttemptCount($stack->cache, 'a'), perAttemptCount($stack->cache, 'b'), perAttemptCount($stack->cache, 't')])->toBe([0, 0, 0])
        ->and([$batch->releaseFailures, $batch->failure])->toBe([[], null]);
});

it('AC-4: goes on past a dry-run rejection, and releases a dry-run count made before an enforcing rejection', function (): void {
    $stack = perAttemptStore();
    perAttemptAtLimit($stack->store, 'd');
    perAttemptAtLimit($stack->store, 's');

    $batch = (new OneCallPerAttempt($stack->store))->attemptAll([
        perAttemptPlan('d', 1, enforces: false), perAttemptPlan('e', 100, enforces: false), perAttemptPlan('s', 1),
    ], false);

    expect(perAttemptSummary($batch))->toBe([['d', false, false], ['e', true, true], ['s', false, false]])
        ->and([perAttemptCount($stack->cache, 'd'), perAttemptCount($stack->cache, 'e')])->toBe([1, 0]);
});

it('AC-5: releases every count at the end when asked, and still returns the results', function (): void {
    $stack = perAttemptStore();

    $batch = (new OneCallPerAttempt($stack->store))->attemptAll([perAttemptPlan('a', 5), perAttemptPlan('b', 5, enforces: false)], true);

    expect(perAttemptSummary($batch))->toBe([['a', true, true], ['b', true, true]])
        ->and(array_map(fn (Attempt $attempt): int => $attempt->remaining, $batch->attempts))->toBe([4, 4])
        ->and([perAttemptCount($stack->cache, 'a'), perAttemptCount($stack->cache, 'b')])->toBe([0, 0]);
});

it('keeps every count when no enforcing rule rejects', function (): void {
    $stack = perAttemptStore();

    $batch = (new OneCallPerAttempt($stack->store))->attemptAll([perAttemptPlan('a', 5), perAttemptPlan('b', 5, enforces: false)], false);

    expect(perAttemptSummary($batch))->toBe([['a', true, false], ['b', true, false]])
        ->and([perAttemptCount($stack->cache, 'a'), perAttemptCount($stack->cache, 'b')])->toBe([1, 1]);
});

it('AC-7: makes one call per attempt and one per release', function (): void {
    $stack = perAttemptStore();
    perAttemptAtLimit($stack->store, 's');
    $store = perAttemptRecording($stack->store);

    (new OneCallPerAttempt($store))->attemptAll([perAttemptPlan('a', 100), perAttemptPlan('b', 100), perAttemptPlan('s', 1)], false);

    expect([$store->attempts, $store->releases])->toBe([3, 2]);
});

it('makes no call for an empty plan', function (): void {
    $store = perAttemptRecording(perAttemptStore()->store);

    $batch = (new OneCallPerAttempt($store))->attemptAll([], true);

    expect([$batch->attempts, $batch->released, $batch->failure])->toBe([[], [], null])
        ->and([$store->attempts, $store->releases])->toBe([0, 0]);
});

it('stops at a failed attempt, keeps the counts made before it, and returns the failure', function (): void {
    $stack = perAttemptStore();
    $store = perAttemptRecording($stack->store, attemptsOf: 'b');

    $batch = (new OneCallPerAttempt($store))->attemptAll([perAttemptPlan('a', 5), perAttemptPlan('b', 5), perAttemptPlan('c', 5)], true);

    // Releasing on a store that just failed would only fail again: stricter, never looser.
    expect(perAttemptSummary($batch))->toBe([['a', true, false]])
        ->and($batch->failure?->getMessage())->toBe('The store is down.')
        ->and([$store->attempts, $store->releases])->toBe([2, 0])
        ->and([perAttemptCount($stack->cache, 'a'), perAttemptCount($stack->cache, 'c')])->toBe([1, 0]);
});

it('still tries every release after one fails, and lists the failed ones by position (ADR-0021)', function (): void {
    $stack = perAttemptStore();
    perAttemptAtLimit($stack->store, 's');
    $store = perAttemptRecording($stack->store, releasesOf: 'a');

    $batch = (new OneCallPerAttempt($store))->attemptAll([perAttemptPlan('a', 100), perAttemptPlan('b', 100), perAttemptPlan('s', 1)], false);

    expect(perAttemptSummary($batch))->toBe([['a', true, false], ['b', true, true], ['s', false, false]])
        ->and(array_keys($batch->releaseFailures))->toBe([0])
        ->and($batch->releaseFailures[0]->getMessage())->toBe('The store could not release.')
        ->and([perAttemptCount($stack->cache, 'a'), perAttemptCount($stack->cache, 'b')])->toBe([1, 0]);
});
