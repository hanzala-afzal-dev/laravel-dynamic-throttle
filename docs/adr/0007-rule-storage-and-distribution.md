# ADR-0007: Rule storage and distribution to servers

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Amended by: [ADR-0020](0020-laravel-ecosystem-coexistence.md) (`dynamic_throttle_*` table names, a configurable connection, published migrations), [SPEC-009](../specs/009-exemptions.md) (the exemptions' rule column is `rule_id`)
- Related: ADR-0005, ADR-0010, ADR-0011

## Context

- Rules are edited in the dashboard, but they must be read on every request, on every server, without touching the database.
- The first draft had several problems:
  - It read the whole rule JSON from Redis on every request.
  - It recompiled twice (once in the controller, once in the observer).
  - It did not recompile when exemptions changed.
  - A missing cache key triggered a database query on the request path, which is a stampede risk.
- PHP-FPM does not keep memory between requests. Only Octane and other long-running workers do.

## Decision

### Source of truth: the database

- Tables:
  - `throttle_rules`
  - `throttle_exemptions`: `throttle_rule_id` nullable, meaning a global exemption
  - `throttle_settings`: runtime switches (ADR-0011)
  - `throttle_audit_log`: ADR-0015
  - `throttle_metrics_hourly`: ADR-0013
- Use **string columns validated by PHP backed enums** instead of database `enum` columns. Adding a scope then needs no migration, and database enums are painful to alter, especially on PostgreSQL.
- Rename `decay_seconds` to `window_seconds`. It is clearer, and the algorithm has no "decay".

### Compiled manifest, versioned

- The compiler reads the tables and writes one JSON manifest containing:
  - `version` (an integer that increases on each compile)
  - the rules, sorted and pre-indexed (ADR-0008)
  - the exemptions and the runtime settings
- Compilation is triggered by model observers on rules, exemptions and settings. The observers run **after the database transaction commits**.
- Compilation runs under a cache lock, so two compiles cannot overwrite each other out of order.
- Controllers never compile directly.
- The Artisan command `dynamic-throttle:compile` exists for deploy scripts.
- A scheduled compile every 5 minutes heals the manifest if the cache was flushed or evicted.

### Read path: layered, no database

| Layer | Where | Lifetime | Useful on |
|---|---|---|---|
| L0 | PHP memory (static) | Refreshed every `manifest.refresh_seconds` (default 2) | Octane, long-running workers |
| L1 | APCu, optional | TTL `refresh_seconds` | PHP-FPM servers with `ext-apcu` |
| L2 | Cache store key `manifest` | No TTL | Everyone |

- Change propagation delay is at most `refresh_seconds` when L0/L1 are used, and immediate otherwise.
- On PHP-FPM without APCu, each limited request costs one small manifest read plus one counter operation per matched rule. This is documented.

### Missing manifest (cache flushed or evicted)

- The request path **never** queries the database synchronously.
- If L0/L1 still hold a last-known manifest, use it.
- Otherwise, apply the failure policy (ADR-0010). One request that wins a short lock schedules a rebuild **after the response is sent**. This is the only allowed database access on a request thread, and it happens after the response.

## Reasoning

- The database is the right home for data admins edit: transactional, auditable, backed up.
- A versioned manifest makes "has anything changed?" a cheap check, and makes stale reads detectable.
- After-commit observers prevent publishing rules from a transaction that later rolls back.
- The layered cache gives Octane users near-zero overhead without hurting FPM users.

## Alternatives considered

- **Rules in config files.** Needs a deploy. Defeats the purpose. Rejected.
- **Query the database on a cache miss, inline.** Risks a stampede exactly when things are already going wrong. Rejected.
- **Pub/sub invalidation** (Redis channels). Redis-only, and needs a subscriber process. Rejected; version polling is simpler.
- **Local PHP files per server** (opcache-friendly). Fast, but multi-server invalidation still needs a version check. Deferred.

## Fallback

- If the 2-second propagation delay is too slow for someone, set `refresh_seconds` to 0 (always read L2).
- If manifest reads show up in benchmarks on FPM, recommend APCu in the docs, and consider local PHP files as L1.
- If the manifest grows too large (more than 500 active rules), show a warning; consider splitting by HTTP method.
- **Revisit when:** benchmarks from Phase 2 are in, or users report stale rules.

## Consequences

- Good: no database queries on the request path, fast propagation, and self-healing.
- Bad: short propagation delay on multi-server setups; more moving parts to test.

## Verification

- Tests:
  - Saving a rule or exemption inside a rolled-back transaction does not change the manifest.
  - A committed save bumps `version`.
  - Deleting the L2 key leads to fail-open behavior plus exactly one scheduled rebuild.
  - With L0 enabled, a change becomes visible within `refresh_seconds` (fake clock).
- Query-count assertion: zero database queries during a throttled request with a warm manifest.

## Decision record

At the Phase 2 exit review, on 2026-09-28, the maintainer decided to build L1. On PHP-FPM, reading and decoding the manifest cost 0.3 to 0.6 ms per request (`benchmarks/results/phase-2-exit.md`). SPEC-028 builds L1 in APCu, as the read path's table above describes, in Phase 3.

On 2026-10-01, for SPEC-028, the maintainer settled how to read L1's lifetime in the read path's table. "TTL `refresh_seconds`" is how long an L1 entry counts as fresh. The entry itself is kept for a day, so that it can serve as the last known manifest when the manifest is missing, as L0's does in a long-running worker.
