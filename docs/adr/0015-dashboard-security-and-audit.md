# ADR-0015: Dashboard security and audit log

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 4
- Related: ADR-0004, ADR-0011, ADR-0014
- Amended by: [ADR-0018](0018-minimal-dashboard.md) (Blade dashboard instead of an SPA)
- Amended: 2026-10-14, the dashboard's routes run behind `['web']` by default, and the gates decide (SPEC-018, see the decision record)
- Amended: 2026-10-30 by [ADR-0031](0031-dashboard-links.md): with `dashboard.links` on, a one-time link from `dynamic-throttle:link` opens the dashboard too, and the audit log records each link made, used and ended (SPEC-034).

## Context

The dashboard can switch off login protection or block all API traffic. Access to it is a powerful lever, and every change must be traceable.

## Decision

1. **Two gates:** `viewDynamicThrottle` (read) and `manageDynamicThrottle` (write).
   - Until the app defines them, access is allowed only in the `local` environment, like Horizon's default.
2. **Middleware:** configurable, default `['web']` (amended 2026-10-14, was `['web', 'auth']`). The gates decide who gets in, and a denied request gets a 403, never a redirect to a login page. Write requests are CSRF-protected; the page's script uses Laravel's XSRF cookie on the same origin.
3. **Audit log** (`throttle_audit_log`), append-only, no update or delete routes. Each entry records:
   - actor id
   - action
   - target
   - before/after JSON
   - IP
   - user agent
   - time

   Written for every change from the dashboard, the API and the CLI (the CLI actor is `cli:<system user>`).
4. **Guardrails:**
   - Confirmation dialogs for dangerous actions: disable or delete an enforcing rule, bypass all, scale below 1.
   - Validation limits:
     - `max_attempts` ≥ 1
     - `window_seconds` from 1 to 86,400
     - pattern length ≤ 255
     - at most 500 active rules
   - `response_override` size ≤ 4 KB.
5. **Self-protection:**
   - Dashboard routes are never subject to dynamic rules (ADR-0004).
   - The dashboard itself is protected by Laravel's **built-in** throttle, not ours, so a bad rule cannot lock admins out.
6. **Recovery path:** if the dashboard is unreachable, the CLI can list, disable and bypass rules (ADR-0011).

## Reasoning

- Separating read from write lets support staff see blocks without changing rules.
- Default-deny outside local avoids exposing an unprotected control plane in production.
- An append-only audit log answers "who changed this and when?" during an incident.
- Using Laravel's own limiter for the dashboard avoids the package locking itself out.

## Alternatives considered

- **A single gate.** Simpler, but too coarse. Rejected.
- **Audit via generic model-history packages.** Adds a dependency and misses CLI and settings changes. Rejected.

## Fallback

- If the gates are too coarse for some teams, allow a policy class override in config.
- **Revisit when:** users ask for approval workflows (a second person confirms). Consider it after 1.0.

## Consequences

- Good: secure defaults and full traceability.
- Bad: users must define the gates before production access works. The docs must lead with this.

## Verification

- An authorization test for every endpoint (guest / viewer / manager).
- A test that every mutation writes exactly one audit entry.
- A test that the dashboard stays reachable under a `*` rule.
- A security review by a Claude Code subagent against a checklist (authorization, validation, mass assignment, XSS in rule names, CSRF), plus manual review.

## Decision record

On 2026-10-01, for SPEC-013, the maintainer decided that the audit log's table, and the CLI's entries, ship in Phase 3, so the CLI-only 0.1.0 audits its changes (ADR-0011 point 6). SPEC-019 adds the JSON API's and the dashboard's entries in Phase 4, and reading them.

On 2026-10-08, the first release moved to the end of Phase 5, with the dashboard ([ADR-0018's decision record](0018-minimal-dashboard.md#decision-record)), so 0.1.0 is not CLI-only after all. The CLI's entries still ship in Phase 3, as decided above.

On 2026-10-14, for SPEC-018, the maintainer chose `['web']` as the default middleware instead of `['web', 'auth']`, as Horizon does. Security is the same: a gate the app has not defined allows the `local` environment only, and a defined gate denies guests unless its callback accepts a null user. `auth` would fail with "Route [login] not defined" in apps without a login page, such as API-only apps, and would ask for a login even in local development.

On 2026-10-14, for SPEC-017, the JSON API's entries moved from SPEC-019 to SPEC-017: each change writes its entry in the same transaction (point 3), from the API's first change endpoint. SPEC-019 reads them.
