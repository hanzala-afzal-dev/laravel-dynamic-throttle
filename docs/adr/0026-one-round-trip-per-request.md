# ADR-0026: One counter-store round trip per request

- Status: Accepted
- Date: 2026-09-28
- Roadmap phase: 3
- Amends: [ADR-0005](0005-storage-abstraction.md) (the `LimiterStore` contract), [ADR-0008](0008-rule-matching.md) (stacking)
- Related: ADR-0010, ADR-0012, ADR-0021, ADR-0025, SPEC-027

## Context

- ADR-0008 stacks rules. Each matched rule that applies counts the request in turn, and the first enforcing rule that rejects stops evaluation. The counts made before it are then released (ADR-0021).
- Each attempt and each release is its own call to the store. On Redis, each is a network round trip, one after another.
- The Phase 2 exit benchmark (`benchmarks/results/phase-2-exit.md`) missed the 1 ms target on local Redis: 1.25 ms at p95 with the manifest in memory. With 3 matched rules, the three round trips are about half of it. The package's own work, measured on the array store, is about 0.34 ms.

## Decision

- **The evaluator still decides, in PHP and in evaluation order, everything that needs no store:** the cap (ADR-0025), exemptions, identities, and which rules apply. It does so for every rule within the cap, before it calls the store.
- **It then makes one call for the request.** The call carries every attempt, in evaluation order, each with its counter key, its limit and window, and whether it enforces.
- **The store counts them in order.** At the first enforcing rule that rejects, it stops. It then releases every count it made for the request, dry-run rules' included (ADR-0021), and returns each attempt's result.
- **The first decision in evaluation order still wins.** A fail-closed rule whose identity cannot be resolved ends evaluation without the store (ADR-0010).
  - The rules before it still go to the store in the same single call, to learn whether one of them rejects: a 429 from an earlier rule stands.
  - When none rejects, their counts are released in that same call, and the request gets the 503.
- **The Redis driver does all of this in one Lua script.** That is one round trip, and it is atomic: no other request sees the counts in between.
- **On Redis Cluster the Redis driver keeps one call per attempt,** as today. A script may only touch keys in one hash slot (ADR-0005), and one request's counters live in many.
- **The cache driver keeps one call per attempt.** A generic cache store has no multi-key script. Its behavior stays ADR-0008's and ADR-0021's.
- **A failed call fails the whole request's counting.** The store counts as unavailable for the request, as after a failed attempt today (ADR-0010). If the reply was lost after the script ran, the counts it made stay: stricter, never looser.

## Reasoning

- Round trips dominate the request's cost on any networked store, and they add up with each matched rule. One call makes the cost flat in the number of matched rules.
- On Redis the batch is also atomic, which removes ADR-0008's accepted flaw on that driver: the brief window in which a released counter is still inflated by one.
- Everything the evaluator decides in PHP stays in PHP: identities, exemptions, the cap and the failure policy. The script only counts and releases, which the model-based tests can check against the PHP reference (ADR-0016).

## Alternatives considered

- **Keep one call per attempt.** Rejected: the round trips are most of the request's cost on Redis.
- **Pipeline the per-attempt scripts.** It is one round trip, but not atomic. Every rule would count before the first rejection is known, so a rejection needs a second round trip to release. Rejected.
- **Independent limiters without releases** (ADR-0008's fallback). It saves nothing on allowed requests, which are most requests. Rejected.

## Fallback

- If the multi-attempt script proves hard to verify, keep it behind the driver and fall back to one call per attempt, the Redis Cluster path, for everyone.
- **Revisit when:** the model-based tests or production reports show the script and the PHP reference disagree.

## Consequences

- Good: one round trip per request on Redis, whatever the number of matched rules, and atomic stacking there.
- Bad: every rule within the cap has its identity resolved before the store answers. So a rule after the one that rejects now runs its resolver, where today it never runs. Custom resolvers already run on every allowed request, so they should be cheap anyway. SPEC-012's AC-4 ("never runs the fail-closed rule after it") changes with SPEC-027.
- Bad: the Lua script grows from one counter to up to 10: the cap's 5 enforcing and 5 dry-run attempts.
- Bad: two code paths on the Redis driver, one script per request and one call per attempt on Cluster. The contract suite runs against both.

## Verification

- The shared contract suite runs the batched call against every store, the Redis driver's per-attempt path included.
- The model-based test drives the batched script and the PHP reference with the same random requests, and they agree.
- A test counts the Redis driver's round trips: one per request with 1, 3 and 10 attempts.
- The Phase 3 benchmark: one store round trip per request, and under 0.5 ms of the package's own time at p95 (`docs/roadmap.md`).

## Decision record

Accepted on 2026-09-28, at the Phase 2 exit review: the maintainer accepted the recommendation behind it. The details (the contract's new method, the script, and SPEC-012 AC-4's change) are SPEC-027's.
