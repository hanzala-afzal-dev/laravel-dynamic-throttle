# ADR-0006: Rate-limit algorithm, keys and time source

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 1
- Related: ADR-0005, SPEC-001

## Context

The first draft used a sliding-window **log**: a Redis sorted set with one entry per request. It had three problems:

- Memory grows with the limit: 10,000 requests per minute means 10,000 entries per client.
- Two requests in the same microsecond share one member and count once.
- It needs sorted sets, so it cannot run on non-Redis stores.

## Decision

### Algorithm: sliding-window counter, for every driver

- Window index `w = floor(now / window)`; time into the window `e = now - w * window`.
- Keep a count for the current window `c` and the previous window `p`.
- Estimated usage: `est = p * (window - e) / window + c`.
- **Allow if `est + 1 <= max`, then count the request.** Rejected requests are not counted, which matches Laravel's built-in behavior.
- Remaining: `max(0, max - ceil(est + 1))`.
- `Retry-After` is solved exactly from the formula (see SPEC-001), rounded up to whole seconds, with a minimum of 1.
- The formula lives in **one pure PHP class**, fully unit-tested. The Lua script is a port of it, and the same contract tests verify both.

### Keys

- Base key: `{prefix}c:{ruleId}:{windowSeconds}:{identityHash}`.
- Redis: one hash per base key; each field is a window index. The script deletes fields older than the previous window.
- Cache driver: one key per window, `{base}:{w}`.
- TTL: `2 × window + 5` seconds.
- Changing a rule's `max_attempts` keeps its counters, so a lower limit takes effect immediately. Changing its window length starts fresh counters, because the window length is part of the key.

### Time source

- **Redis driver:** `TIME` inside the Lua script, so all app servers agree on the clock. The script accepts an optional time argument, used only by tests.
- **Cache driver:** the application clock (`now()`, so Laravel's time-travel test helpers work). Servers need NTP; a skew of a few hundred milliseconds only shifts window edges slightly.

## Reasoning

- **Fixed window** allows bursts of up to twice the limit around window boundaries.
- **Sliding log** is exact but heavy, and Redis-only.
- **Token bucket / GCRA** handle bursts nicely, but need an atomic read-modify-write. That means Lua on Redis, or locks on other stores.
- **Sliding counter** needs only increment and read, so it works on every store. It stores two numbers per client, and its accuracy is good in practice; Cloudflare has written about using this approach at scale.

## Alternatives considered

- Fixed window: boundary bursts. Rejected as the default.
- Sliding log: memory and portability. Rejected.
- GCRA: portability. Deferred as an optional Redis-only algorithm.

## Fallback

- If the `Retry-After` math or the Lua port is still buggy near the release date, ship **fixed window** (Laravel-compatible) behind the same interface and move the sliding counter to 0.2.
- If users need burst allowances, add GCRA as an opt-in, Redis-only algorithm per rule.
- **Revisit when:** accuracy complaints come in, or users ask for bursts.

## Consequences

- Good: constant memory per client, one round trip per rule on Redis, and the same behavior on every driver.
- Bad: the estimate assumes traffic in the previous window was evenly spread, so bursty traffic can be slightly over- or under-allowed. Documented.

## Verification

- SPEC-001 table-driven unit tests for the formula and `Retry-After`.
- Contract tests: the same sequences give the same decisions on every driver.
- Model-based test: random request sequences with a fake clock. The Redis driver's decisions must match the pure PHP reference, and no aligned window ever counts more than `max`.
