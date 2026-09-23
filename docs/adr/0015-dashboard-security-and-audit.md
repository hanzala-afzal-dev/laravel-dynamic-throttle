# ADR-0015: Dashboard security and audit log

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 4
- Related: ADR-0004, ADR-0011, ADR-0014

## Context

The dashboard can switch off login protection or block all API traffic. Access to it is a powerful lever, and every change must be traceable.

## Decision

1. **Two gates:** `viewDynamicThrottle` (read) and `manageDynamicThrottle` (write).
   - Until the app defines them, access is allowed only in the `local` environment, like Horizon's default.
2. **Middleware:** configurable, default `['web', 'auth']`. Write requests are CSRF-protected; the SPA uses Laravel's XSRF cookie on the same origin.
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
