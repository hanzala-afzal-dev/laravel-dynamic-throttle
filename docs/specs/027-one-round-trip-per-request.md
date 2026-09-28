# SPEC-027: One store round trip per request

- Status: In progress
- ADRs: ADR-0026, ADR-0005, ADR-0008, ADR-0010, ADR-0012, ADR-0021, ADR-0025
- Roadmap phase: 3

## Problem
Each rule that counts a request makes its own call to the counter store, one after another, and so does each release after a rejection. On Redis, each call is a network round trip. At the Phase 2 exit, three matched rules cost about half of the middleware's 1.25 ms at p95 on local Redis (`benchmarks/results/phase-2-exit.md`). ADR-0026 decides that a request counts in one call. The Redis driver makes that call one Lua script.

## Behavior

### Evaluation in three steps
1. **Plan, in PHP, without the store.** The evaluator walks the matched rules in evaluation order, as today:
   - it applies the cap (ADR-0025) and the exemptions (SPEC-009);
   - it resolves each rule's identity (SPEC-008);
   - it lists the rules that apply as planned attempts: counter key, limit, window, and whether the rule enforces.
   - It does this for every rule within the cap, before any store call, so a rule after the one that will reject has its identity resolved too. That is the change ADR-0026 accepts, and SPEC-012's AC-4 changes with it.
   - **Planning stops at an enforcing fail-closed rule whose identity cannot be resolved.** Evaluation ends there (SPEC-012), and the rules after it never run.
2. **Count, in one call.** The store gets the planned attempts, in order, and:
   - counts each one;
   - at the first enforcing rule that rejects, stops, and releases every count it made for the request, dry-run rules' included (ADR-0021);
   - when planning stopped at a fail-closed rule, releases every count at the end, even without a rejection: the call only asked whether an earlier rule rejects;
   - returns each attempt's result, and whether its count was released.
3. **Decide, in PHP, in evaluation order.** The first enforcing rejection gives the 429. Otherwise, a fail-closed rule that stopped planning gives the 503. Otherwise, the request goes through. Nothing else about the outcome, the headers, the events or the failure policy changes.

### The stores
- **The Redis driver** counts all the attempts in one Lua script, which is one round trip and atomic. Each attempt runs the same arithmetic as today's script, so both make identical decisions.
  - The script reads Redis `TIME` once, so every attempt of a request uses the same time.
- **On Redis Cluster**, a script may only touch keys in one hash slot (ADR-0005). There the Redis driver makes one call per attempt, as the cache driver does.
- **The cache driver, and any other `LimiterStore`**, keep one call per attempt. The evaluator runs them in order, with the same behavior as the batch: it stops at the first enforcing rejection, releases, and still tries every release after one fails (ADR-0021).

### Failures (ADR-0010, SPEC-012)
- **A batch that fails** fails the whole call. Nothing is known about its counts, so none is released: stricter, never looser.
  - The failure is listed on the first planned attempt, and the cooldown starts.
  - Every later planned rule goes unchecked, except that the first enforcing fail-closed rule among them answers 503.
- **One call per attempt keeps today's behavior:**
  - A failed attempt keeps the counts made before it, and marks the store down for the rest of the request.
  - A failed release is listed, starts the cooldown, and the other releases are still tried.
- **During a cooldown** no call is made, as today.

## Acceptance criteria
Unit tests use the array store and the FakeClock. Driver tests run on Redis and Valkey, with phpredis and predis.

- **AC-1 (one round trip)** On the Redis driver, a request with 1, 3 or 10 planned attempts sends exactly one script call to Redis. A test counts the commands through Laravel's Redis command events.
- **AC-2 (same decisions)** For 200 random plans, each with 1 to 10 attempts, random limits, windows, modes, prior counts and times, the batch script and the PHP reference give identical results: each attempt's decision, remaining, retry-after and window index, where the batch stopped, and which counts it released. This is the model-based test (ADR-0016).
- **AC-3 (stop and release)** Plans `[a: 100, b: 100, s: 1]`, all enforcing, after one earlier request: `s` rejects, `a` and `b` are released, and a later rule `t` is never counted.
- **AC-4 (dry-run)** A dry-run rule over its limit is reported as would-block and does not stop the batch. A dry-run count made before an enforcing rejection is released.
- **AC-5 (release all)** With planning stopped at a fail-closed rule, the call counts `[a, b]`, releases both, and returns their results. `a`'s and `b`'s counts are unchanged afterwards.
- **AC-6 (Redis Cluster)** On a cluster connection, phpredis's or predis's, the Redis driver makes one call per attempt, and the results match AC-3 to AC-5.
- **AC-7 (other stores)** The cache driver, and a `LimiterStore` that only implements `attempt()` and `release()`, give AC-3's to AC-5's results with one call per attempt.
- **AC-8 (the first decision wins)** A rule that rejects, then an enforcing fail-closed rule whose resolver throws:
  - The response is the 429, the tenant resolver's error is reported, and no 503 is sent.
  - With the first rule under its limit, the response is the 503, and the first rule's count is released.
  - On Redis, each of these requests is one round trip.
- **AC-9 (a failed batch)** A Redis driver whose script fails, with an open rule then a closed rule:
  - One failure is listed, on the first rule.
  - The open rule goes unchecked, and the closed rule answers 503.
  - The cooldown starts, and the next request makes no call.
- **AC-10 (unchanged elsewhere)** Every existing evaluator, middleware, failure-policy and dry-run test passes, except SPEC-012's AC-4. That one now expects the tenant resolver's error to be reported, since the resolver runs before the store answers.
- **AC-11 (arch)** Only the Redis driver sends scripts, and its keys come from `KeyBuilder`.

## Interfaces and files
- `src/Engine/Contracts/BatchLimiterStore.php`: an optional interface for stores that count a request's attempts in one call. It has one method, `attemptAll(list<PlannedAttempt> $attempts, bool $releaseAll): AttemptBatch`, which never throws a storage error: a failure is part of the result.
- `src/Engine/PlannedAttempt.php`: an attempt to make, with its counter key, limit, window, and whether it enforces.
- `src/Engine/AttemptBatch.php`: the results, in order:
  - the attempts made, up to and including the first enforcing rejection;
  - whether each one's count was released;
  - the releases that failed;
  - the failure that stopped the batch, if any.
- `src/Engine/Stacking/OneCallPerAttempt.php`: the batch's behavior for any `LimiterStore`, with one call per attempt. It is today's loop, moved out of the evaluator.
- `src/Engine/Stacking/RuleEvaluator.php`: plans, counts, then decides. It uses the store's `attemptAll()` when the store is a `BatchLimiterStore`, and `OneCallPerAttempt` otherwise.
- `src/Engine/Stores/RedisLimiterStore.php`: implements `BatchLimiterStore` with a batch script, and uses one call per attempt on cluster connections.
- Tests:
  - `tests/Unit/Engine/Stacking/`: the evaluator's plan, count and decide steps, and `OneCallPerAttempt`;
  - `tests/Contract/`: the batch behavior on every store;
  - `tests/Drivers/`: AC-1, AC-2 and AC-6 on Redis and Valkey;
  - `tests/Feature/Http/`: AC-8 and AC-9 through the middleware;
  - `tests/Feature/Http/FailurePolicyTest.php`: SPEC-012's AC-4, changed.

## Out of scope
- **Grouping a request's attempts by hash slot on Redis Cluster.** It would not be atomic across slots, so Cluster keeps one call per attempt.
- **Batching on the cache driver.** A generic cache store has no multi-key script.
- **The manifest's APCu cache** (SPEC-028) and the container bindings (the `perf:` PR before this spec).

## Edge cases and failure modes
- **An empty plan** (no rule applies) makes no call.
- **Up to 10 attempts** per request, the cap's 5 enforcing and 5 dry-run, so the script stays small.
- **The reply is lost after the script ran.** The counts stay, as after a failed attempt today.
- **A script error on some Redis-compatible server:** the batch fails, and the failure policy applies. The fallback, one call per attempt for everyone, is ADR-0026's.
- **`NOSCRIPT` after a restart or failover:** the script is sent again with `EVAL`, as today's scripts are.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate`, since the evaluator and the stores are in `src/Engine`
- `composer bench`: `MiddlewareBench` on local Redis, recorded in `benchmarks/results/`

## Decisions
Recorded as recommended (ADR-0027). The maintainer can veto them in the PR.
1. **An optional interface, not a new method on `LimiterStore`.** The cache driver, test doubles and any store an app wrote keep working unchanged, and the one-call-per-attempt path is today's code.
2. **A batch failure is part of the result, not an exception.** Both paths report partial results the same way: the one-call-per-attempt path can fail after some attempts counted.
3. **Identities are resolved before the store call** for every rule within the cap, as ADR-0026 accepts. Resolving lazily would need one round trip per rule again.
4. **Cluster is detected from Laravel's cluster connection classes,** so there is nothing to configure.
