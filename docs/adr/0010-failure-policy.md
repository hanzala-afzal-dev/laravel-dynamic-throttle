# ADR-0010: Failure policy when storage breaks

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Related: ADR-0005, ADR-0007, ADR-0011

## Context

- In the first draft, a Redis exception inside the middleware turned into a 500 error for every request, so the rate limiter could take the whole app down.
- There are three distinct failures:
  - the counter store is unreachable
  - the manifest is missing (ADR-0007)
  - the store is slow (timeouts)

## Decision

1. **Default fail-open.** If a counter operation fails, allow the request, log a warning (rate-limited logging) and dispatch a `StorageUnavailable` event.
2. **Per-rule fail-closed.** Rule field `on_failure`: `open` (default) or `closed`.
   - A fail-closed rule responds with **503** and a short `Retry-After` when its counter cannot be checked.
   - Meant for sensitive endpoints such as login and password reset.
   - 503, not 429: the client did nothing wrong.
3. **No manifest, no rules.** Without any manifest (no L0/L1/L2), fail-closed rules are unknown too, so the request passes. The health check and logs make this loud.
4. **Short-circuit.** After a storage failure, mark the store "down" for `failure.cooldown_seconds` (default 10) in L0/APCu, so later requests skip the store instead of each waiting for a timeout.
5. **Timeouts.** The docs recommend a low connection and read timeout for the limiter's Redis connection (a few hundred milliseconds), ideally a dedicated connection.
6. **No exception from storage ever escapes the middleware.** Enforced by tests.

## Reasoning

- For most apps, briefly losing rate limiting is far better than an outage.
- Some endpoints are worth protecting even at the cost of availability, so the choice is per rule.
- The short-circuit stops a slow Redis from multiplying latency across every request, where the runtime keeps memory.
- Honest limitation: on PHP-FPM without APCu, each request still pays one timeout. That is why low timeouts are recommended.

## Alternatives considered

- **Global fail-closed.** Turns a cache outage into a full outage. Rejected.
- **Fall back to the database** for counters during an outage. Moves the load to the database exactly when things are fragile. Rejected.
- **Fall back to in-memory counters per server.** Gives limits that silently multiply by server count and reset constantly; misleading. Rejected.

## Fallback

- If attackers learn to knock over the cache to disable limits, mark critical rules fail-closed, keep edge rate limiting as defense in depth, and consider a small per-server emergency limiter (APCu) for fail-closed rules.
- **Revisit when:** incidents show fail-open being abused, or users ask for more granular policies.

## Consequences

- Good: the package cannot cause an outage by default.
- Bad: during a storage outage, protection is reduced. Documented in the runbook.

## Verification

- Tests with a store that throws on every call:
  - Fail-open rules allow the request.
  - Fail-closed rules return 503 with `Retry-After`.
  - The event is dispatched and logging is rate-limited.
- A test proving the cooldown skips the store until it expires (fake clock).
- A runbook scenario "Redis down" in `docs/runbook.md`, with the matching test.
