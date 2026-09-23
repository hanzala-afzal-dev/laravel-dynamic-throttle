# ADR-0013: Telemetry and metrics

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 3
- Related: ADR-0004, ADR-0012, ADR-0014

## Context

- The first draft had a `throttle_metrics_hourly` table, but nothing ever filled it.
- Metrics must never slow down or break requests, and must never write to the database on the request path.

## Decision

1. **Record after the response.** The middleware collects counts during the request and writes them in `terminate()`. On PHP-FPM this runs after the response has been sent.
2. **Buffer in the store, not the database.** Per rule, per minute, increment `hits`, `blocks` and `would_blocks` keys (TTL 2 hours). On Redis these are pipelined into one round trip.
3. **Top offenders.**
   - Redis driver: per rule and hour, a sorted set of blocked identity hashes (`ZINCRBY`), keeping the top 100.
   - Cache driver: offenders are **not** tracked (no sorted sets). The dashboard says so plainly.
4. **Flush.** The scheduled command `dynamic-throttle:flush-metrics` (every minute) moves buckets into `throttle_metrics_hourly` with upserts.
5. **Prune.** `dynamic-throttle:prune --hours=720` (default 30 days) removes old metrics.
6. **Events for integrations:**
   - `RateLimitExceeded`
   - `RateLimitWouldBlock`
   - `StorageUnavailable`
   - `RuleChanged`

   Apps connect these to Slack, Sentry, Pulse and so on.
7. **Safety.** Telemetry is wrapped in try/catch, can be disabled (`telemetry.enabled`), and has a sampling rate (`telemetry.sample_rate`, default 1.0).

## Reasoning

- `terminate()` plus pipelining keeps the cost off the user's response time.
- Buffering in the store turns thousands of writes into one upsert per rule per minute.
- Events let users plug into tools they already use, instead of the package building alerting channels.
- Being honest about cache-driver limits beats half-working offender lists.

## Alternatives considered

- **Write every event to the database.** Kills performance at scale. Rejected.
- **Rely on Laravel Pulse only.** Adds a dependency, and not everyone runs Pulse. Rejected as the core; an optional Pulse card is planned after 1.0.
- **Built-in Slack/email alerts.** Scope creep; events cover it. Deferred.

## Fallback

- If telemetry overhead exceeds about 10% of the middleware's cost in benchmarks, lower the default sampling rate and document it.
- If the scheduler is not running (a common misconfiguration), the health check shows "metrics not flushed for more than 10 minutes".
- **Revisit when:** users ask for offender tracking on non-Redis stores. A sampled database approach could work.

## Consequences

- Good: useful numbers at near-zero request cost.
- Bad: depends on the Laravel scheduler running, and offender lists are Redis-only.

## Verification

- Tests:
  - Metrics are recorded in `terminate()`, not in `handle()`.
  - A throwing store does not break the response.
  - The flush command upserts correctly and is idempotent when run twice.
  - The prune command respects retention.
- Benchmark: middleware cost with telemetry on vs. off.
