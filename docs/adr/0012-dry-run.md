# ADR-0012: Dry-run semantics

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Amended by: [ADR-0021](0021-compensation-dry-run-and-failed-release.md) (dry-run rules are released like enforcing rules)
- Related: ADR-0008, ADR-0013

## Context

Dry-run is the package's main differentiator (ADR-0001). Its behavior must be precise, or the "would block" numbers will be wrong.

## Decision

- A dry-run rule is matched, exemption-checked and **counted like an enforcing rule**, but it **never rejects** a request.
- When it would reject, the package:
  - dispatches a `RateLimitWouldBlock` event
  - increments the rule's `would_block` metric (ADR-0013)
- Dry-run rules **do not set rate-limit headers**, so clients are not misled about limits that are not enforced.
- Dry-run rules take no part in compensation (ADR-0008), because they never reject.
- The dashboard shows, per dry-run rule, "would have blocked N requests from M distinct clients in the last 24h", next to a **Promote to enforcing** action that is audited.
- Changing a rule from enforcing to dry-run, or back, keeps its counters.

## Reasoning

- Counting exactly like an enforcing rule is what makes the numbers trustworthy: "if you had enforced this, these requests would have been blocked".
- No headers: headers describe enforcement. A client should not back off because of a rule that is not active.
- Keeping counters on mode change avoids a burst right after promotion.

## Alternatives considered

- **Count without the would-block event.** Loses the per-client detail that makes dry-run useful. Rejected.
- **Shadow copies** (an enforcing rule plus a dry-run variant with a new limit, evaluated side by side). Very useful for changing an existing limit, but more complex. Deferred to v2.

## Fallback

- If dry-run counting adds noticeable load on busy apps, add a per-rule sampling rate for would-block events. Counting itself stays exact.
- **Revisit when:** users ask to test a change to an existing enforcing rule; implement shadow copies.

## Consequences

- Good: operators can safely try limits in production.
- Bad: dry-run rules cost the same as enforcing rules.

## Verification

- Feature tests:
  - A dry-run rule never returns 429.
  - It fires the event exactly when an enforcing twin would reject.
  - It sets no headers.
  - Its counters match an enforcing rule fed the same traffic.
