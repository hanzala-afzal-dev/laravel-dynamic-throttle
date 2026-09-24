# ADR-0025: The per-request cap counts attempts, per mode

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2
- Amends: [ADR-0008](0008-rule-matching.md) ("Hard limit: `matching.max_rules_per_request`")
- Related: ADR-0012, ADR-0022, SPEC-007, SPEC-008

## Context

- ADR-0008 caps each request at `matching.max_rules_per_request` rules, default 5, to bound its cost. Each rule that applies costs a trip to the counter store.
- SPEC-007 applies the cap to matches, before any identity is known, so rules that count nothing still use slots:
  - **Skipped rules.** A rule whose identity is missing, and whose `on_missing_identity` is `skip`, does not apply to the request (ADR-0022). It still takes a slot. Five logged-in-only rules can push a guest rule past the cap, and then guests are never limited. That is ADR-0022's own example policy.
  - **Dry-run rules.** They count requests but never reject them (ADR-0012). They also take slots, so adding a dry-run rule to try a policy can push an enforcing rule past the cap and switch it off, the opposite of what dry-run promises.

## Decision

- The cap counts **attempts on the counter store**, separately for each mode:
  - At most `matching.max_rules_per_request` enforcing rules count a request.
  - At most as many dry-run rules count it, on top.
- A rule that does not apply to the request makes no attempt and takes no slot, whether its identity is missing on a `skip` rule or, later, it is exempt (SPEC-009).
- A dry-run rule takes a dry-run slot, never an enforcing one, so adding a dry-run rule never switches off an enforcing rule.
- The evaluator applies the cap, because only it knows which rules apply. The matcher returns every match in manifest order.
- A rule whose mode has no slot left is ignored: it is not attempted, its identity is not resolved, and it is listed in the evaluation's ignored rules. The health check reports those (SPEC-016).

## Reasoning

- The cap exists to bound store round trips, and a rule that does not apply makes none.
- Dry-run rules are meant to be safe to add. With separate slots, they cannot change what enforcing rules do.
- Matching and resolving identities stay cheap: the index bounds the candidates (ADR-0023), and each scope is resolved at most once per request (SPEC-008).

## Alternatives considered

- **Cap matches, as SPEC-007 did.** Skipped and dry-run rules push enforcing rules out. Rejected.
- **One shared cap on attempts.** Skipped rules stop taking slots, but a dry-run rule can still switch off an enforcing one. Rejected.
- **No cap on dry-run rules.** A request's cost has no bound. Rejected.
- **A separate config key for dry-run slots.** It is one more setting for little gain. Rejected for now: see the fallback.

## Fallback

- If the doubled worst case shows up in the middleware benchmark, add `matching.max_dry_run_rules_per_request` with a smaller default.
- **Revisit when:** the Phase 2 benchmark shows dry-run rules costing more than the target allows.

## Consequences

- Good: rules that do not apply, and dry-run rules, can never switch off an enforcing rule.
- Bad: in the worst case a request makes twice as many store attempts, 10 by default, when at least 5 dry-run rules match it too. Dry-run rules are temporary by nature: they get promoted or deleted.
- Bad: more rules may have their identity resolved than before, including every skipped one. Resolution is memoized per scope and needs no store.

## Verification

- Guests and `skip`: five `user` rules with `skip` at priorities 1 to 5, and an `ip` rule at priority 100 with max 1. A guest's first request is counted by the `ip` rule, and its second is rejected by it.
- Dry-run slots: a dry-run rule at priority 1 and five enforcing rules after it. All six count the request.
- Caps per mode:
  - Seven enforcing rules: the first five count the request, and the other two are listed as ignored.
  - Seven dry-run rules and one enforcing rule after them: five dry-run rules count, two are ignored, and the enforcing rule counts.
- An ignored rule's identity is never resolved.
- The container's evaluator takes its cap from `matching.max_rules_per_request`, and the matcher returns every match.
