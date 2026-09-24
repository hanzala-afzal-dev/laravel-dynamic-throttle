# ADR-0021: Compensation for dry-run rules and failed releases

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2
- Amends: [ADR-0010](0010-failure-policy.md) (a failed release), [ADR-0012](0012-dry-run.md) (dry-run rules and compensation)
- Related: ADR-0008, SPEC-004

## Context

Writing SPEC-004 (`release`) showed two places where the accepted ADRs disagree:

1. **Dry-run rules and compensation.** Say a dry-run rule matched before an enforcing rule that rejects the request:
   - ADR-0012 says dry-run rules "take no part in compensation (ADR-0008), because they never reject".
   - ADR-0012 also says a dry-run rule's counters "match an enforcing rule fed the same traffic", and ADR-0008 releases every rule evaluated before the rejecting one. So an enforcing twin would be released, and a dry-run rule that keeps the count no longer matches it.
2. **A failed release.** ADR-0010 says "if a counter operation fails, allow the request". But a release runs after an enforcing rule rejected the request, based on a counter that worked. Read literally, a failed release turns that rejection into an allowed request.

## Decision

1. **Dry-run rules are released like enforcing rules** when a later enforcing rule rejects the request. They never trigger a release themselves, because they never reject. This replaces ADR-0012's "Dry-run rules take no part in compensation".
2. **A failed release keeps the rejection.** Fail-open (ADR-0010 point 1) is for when the limiter cannot decide. It does not apply to cleanup after a decision. After a failed release, the middleware:
   - still reports the failure: a rate-limited log entry, `report()` (ADR-0020) and the `StorageUnavailable` event
   - starts the ADR-0010 cooldown, as for any storage failure
   - still tries the remaining releases

## Reasoning

- Dry-run numbers are only worth something if they match what enforcing would have done. Promoting a rule should behave as its dry-run predicted.
- The rejecting rule's counter worked, so its decision stands. If a failed cleanup could turn a 429 into a 200, anyone able to make storage flaky could get around the limits.
- A failed release is the same health signal as a failed attempt, so it starts the same cooldown.

## Alternatives considered

- **Never release dry-run rules** (ADR-0012's text). Their counts would include requests that an enforcing twin would give back, so the would-block numbers come out too high. Rejected.
- **Fail open on a failed release** (ADR-0010's text). It gives attackers a way around the limits. Rejected.
- **Retry a failed release.** Retries add latency to rejected requests during an outage, and the extra count expires with its window anyway. Rejected.

## Fallback

- Releasing dry-run rules costs one round trip per dry-run rule matched before the rejecting rule, and only on rejected requests. If that shows up in benchmarks, count dry-run rules after the enforcing ones instead, and document it.
- **Revisit when:** users report dry-run numbers that disagree with enforcement after promotion.

## Consequences

- Good: a dry-run rule's counters match an enforcing twin, so promotion holds no surprises.
- Good: storage trouble can never turn a rejection into an allowed request.
- Bad: rejected requests pay one more round trip for each dry-run rule released.
- Bad: after a failed release, the earlier rule keeps one extra count until its window expires. That is stricter, never looser (SPEC-004).

## Verification

- Feature tests (SPEC-010, SPEC-011):
  - A dry-run rule matched before a rejecting enforcing rule ends with the same counter as an enforcing twin fed the same traffic.
  - A dry-run rule that would reject triggers no release.
- Feature test (SPEC-012), with a store whose release throws:
  - The request is still rejected with a 429.
  - `StorageUnavailable` is dispatched once.
  - The store is skipped during the cooldown.
