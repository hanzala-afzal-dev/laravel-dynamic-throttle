# ADR-0011: Emergency controls

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 3
- Related: ADR-0007, ADR-0015

## Context

- The first draft read `bypass_all` and `scale_factor` from `.env`. Changing `.env` usually needs a config reload or deploy, which is exactly what the package promises to avoid, and exactly when speed matters most.
- Emergency changes are also easy to forget and leave on.

## Decision

1. **Runtime settings in the database** (`throttle_settings`), compiled into the manifest:
   - `bypass_all` (bool)
   - `scale_factor` (float, 0.1 to 10)
   - each can have an optional **`expires_at`**
2. **Three ways to change them:**
   - the dashboard
   - Artisan: `dynamic-throttle:bypass on --for=30m`, `dynamic-throttle:scale 2 --for=2h`, `dynamic-throttle:status`
   - the JSON API
3. **Auto-revert.** Expired settings are ignored at read time, so a forgotten "scale ×2" ends by itself.
4. **Hard kill switch.** `DYNAMIC_THROTTLE_ENABLED=false` in config turns the middleware into a no-op. It needs a config reload, but works even when the database and cache are both broken.
5. **Scaling math.** Effective limit = `max(1, ceil(max_attempts × scale_factor))`. Scaling does not reset counters, because the window length is unchanged (ADR-0006).
6. Every change is written to the audit log (ADR-0015).

## Reasoning

- The dashboard can be unreachable during an incident, so the CLI path is essential.
- Expiry turns "remember to undo this" into a default.
- The environment kill switch is the last resort that depends on nothing else.

## Alternatives considered

- **Env-only switches.** Too slow in an incident. Rejected.
- **Cache-only switches** (no database row). Lost on cache flush and not auditable. Rejected.

## Fallback

- If the database is down but the cache works, the last compiled manifest keeps the last settings. The env kill switch covers the case where both are down.
- **Revisit when:** users ask for scheduled changes (for example, "double limits during the campaign on Friday"). `expires_at` plus a start time would cover it.

## Consequences

- Good: fast, safe, reversible incident response.
- Bad: one more table and code path to maintain.

## Verification

- Tests:
  - A scale factor applies after compile.
  - Expired settings are ignored (fake clock).
  - Bypass skips all rules.
  - The env kill switch works with the database and cache unavailable.
  - The CLI commands write audit entries.
