# SPEC-027: One store round trip per request

- Status: Done
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
- **The Redis driver** counts all the attempts in one Lua script, which is one round trip and atomic. Each attempt runs the same arithmetic as today's script, so both make identical decisions: the two scripts share one counting function.
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
- **AC-2 (same decisions)** For 300 random plans, each with 1 to 10 attempts, random limits, windows, modes, prior counts and times, the batch script and the PHP reference give identical results: each attempt's decision, remaining, retry-after and window index, where the batch stopped, and which counts it released. This is the model-based test (ADR-0016).
- **AC-3 (stop and release)** Plans `[a: 100, b: 100, s: 1]`, all enforcing, after one earlier request: `s` rejects, `a` and `b` are released, and a later rule `t` is never counted.
- **AC-4 (dry-run)** A dry-run rule over its limit is reported as would-block and does not stop the batch. A dry-run count made before an enforcing rejection is released.
- **AC-5 (release all)** With planning stopped at a fail-closed rule, the call counts `[a, b]`, releases both, and returns their results. `a`'s and `b`'s counts are unchanged afterwards.
- **AC-6 (Redis Cluster)** On a cluster connection, phpredis's or predis's, the Redis driver makes one call per attempt, and the results match AC-3 to AC-5.
- **AC-7 (other stores)** The cache driver, and a `LimiterStore` that only implements `attempt()` and `release()`, give AC-3's to AC-5's results with one call per attempt.
- **AC-8 (the first decision wins)** A rule that rejects, then an enforcing fail-closed rule whose resolver throws:
  - The response is the 429, the tenant resolver's error is reported, and no 503 is sent.
  - With the first rule under its limit, the response is the 503, and the first rule's count is released.
  - On Redis, each of these requests is one round trip.
  - In strict mode (SPEC-012 AC-9), the tenant resolver's error is rethrown instead of the 429, as any resolver error is.
- **AC-9 (a failed call)** A store that counts in one call, and whose call fails, with two fail-open rules then a fail-closed rule:
  - One failure is listed, on the first rule, and reported once.
  - The second rule goes unchecked, and the fail-closed rule answers 503.
  - The cooldown starts, and the next request makes no call.
  - On the Redis driver, a script that cannot run returns its failure instead of throwing it.
- **AC-10 (unchanged elsewhere)** Every existing evaluator, middleware, failure-policy and dry-run test passes, except SPEC-012's AC-4. That one now expects the tenant resolver's error to be reported, since the resolver runs before the store answers.
- **AC-11 (same counters)** On every store, a count the batch keeps is seen by a later single attempt on the same counter: both use `KeyBuilder`'s counter keys.

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
  - The attempt script and the batch script share their counting function, so their arithmetic cannot drift apart.
  - The three scripts' SHA-1s are constants: hashing the batch script on every call cost 5 µs. The stubbed-client tests check each constant against its script.
- Tests:
  - `tests/Unit/Engine/Stacking/`: the evaluator's plan, count and decide steps, and `OneCallPerAttempt`;
  - `tests/Contract/`: the batch behavior on every store, AC-11 included;
  - `tests/Drivers/`: AC-1, AC-2, AC-6, AC-8's round trips and AC-9's failed script on Redis and Valkey, and the batch with Redis `TIME` and with Laravel's key prefix;
  - `tests/Unit/Engine/Stores/`: the batch script's arguments and malformed replies, through stubbed clients;
  - `tests/Feature/Http/StrictModeTest.php`: AC-8 in strict mode;
  - `tests/Feature/Http/FailurePolicyTest.php`: AC-9 through the middleware, with a store double whose one call fails;
  - the same file: SPEC-012's AC-4, changed, which is AC-8's 429 through the middleware. SPEC-012's AC-3 is its 503.

## Out of scope
- **Grouping a request's attempts by hash slot on Redis Cluster.** It would not be atomic across slots, so Cluster keeps one call per attempt.
- **Batching on the cache driver.** A generic cache store has no multi-key script.
- **The manifest's APCu cache** (SPEC-028) and the container bindings (the `perf:` PR before this spec).

## Edge cases and failure modes
- **An empty plan** (no rule applies) makes no call.
- **An invalid limit or window** in the plan throws an `InvalidArgumentException` before anything is counted, as `attempt()` does (SPEC-002 AC-16). The compiler never writes one.
- **Up to 10 attempts** per request, the cap's 5 enforcing and 5 dry-run, so the script stays small.
- **The reply is lost after the script ran.** The counts stay, as after a failed attempt today.
- **A store that breaks the contract,** such as one an app wrote: when `attemptAll()` throws, or answers fewer attempts than planned without stopping at an enforcing rejection, the call failed on the first attempt it did not answer, and the failure policy applies. The Redis driver refuses such a reply as unexpected.
- **A script error on some Redis-compatible server:** the batch fails, and the failure policy applies. The fallback, one call per attempt for everyone, is ADR-0026's.
- **`NOSCRIPT` after a restart or failover:** the script is sent again with `EVAL`, as today's scripts are.
- **An endpoint that requires a script's keys to share a hash slot** behind a plain connection, such as ElastiCache Serverless or a slot-aware proxy, refuses the batch script with `CROSSSLOT`. That is a failed call, and the error names `CROSSSLOT`. The README says to use a cluster connection there, which makes one call per attempt, or, where the endpoint supports no cluster client, the cache driver.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate`, since the evaluator and the stores are in `src/Engine`
- `composer bench`: `MiddlewareBench` on local Redis, recorded in `benchmarks/results/phase-3-one-round-trip.md`. With 3 matched rules, a request on local Redis is 150 to 220 µs faster at p50, and the array store shows no cost.

## Decisions
Recorded as recommended (ADR-0027). The maintainer can veto them in the PR.
1. **An optional interface, not a new method on `LimiterStore`.** The cache driver, test doubles and any store an app wrote keep working unchanged, and the one-call-per-attempt path is today's code.
2. **A batch failure is part of the result, not an exception.** Both paths report partial results the same way: the one-call-per-attempt path can fail after some attempts counted.
3. **Identities are resolved before the store call** for every rule within the cap, as ADR-0026 accepts. Resolving lazily would need one round trip per rule again.
4. **Cluster is detected from Laravel's cluster connection classes,** so there is nothing to configure.
5. **No automatic fallback on `CROSSSLOT`.** Retrying one call per attempt would keep a misconfigured deployment working, but it would pay an extra round trip on every request, and nobody would learn why. The failed call is reported instead, and the fix is one config change.
