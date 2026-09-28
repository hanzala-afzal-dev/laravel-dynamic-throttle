# ADR-0027: A faster loop after Phase 2

- Status: Accepted
- Date: 2026-09-28
- Roadmap phase: 3 onward
- Amends: [ADR-0003](0003-development-method.md) (the loop for every feature)

## Context

- ADR-0003 asked to revisit the loop after Phase 2, comparing the time spent per phase.
- In Phase 2, most of a spec's elapsed time was waiting, not work:
  - Each spec waited for the maintainer's answers to its open questions before its tests. The maintainer accepted every recommendation for SPEC-011 and SPEC-012, and nearly all earlier ones.
  - Each spec waited for the previous PR to merge before it started.
- The checks were worth their cost: every spec's review or mutation run found real defects. For example, SPEC-012's review found that one app's outage put every other app on the server into the cooldown.

## Decision

1. **Recommendations are the default.** A spec's open questions still get options and a recommendation. Claude records the recommendation as the decision and goes on to the tests and the code. The maintainer vetoes decisions in the PR review, and a veto is reworked in the same PR.
   - Claude still stops and asks first when a question conflicts with an ADR, when it fixes a public API that would be hard to change after a release, or when no option is clearly best.
   - Each spec's PR lists its decisions, so the maintainer can veto them.
2. **One PR per spec, opened when the spec is done.** There is no draft PR for the spec alone. The commits keep ADR-0003's order: the spec and its decisions, the failing tests, the code, the review's fixes, and the close.
3. **Stacked branches.** While the maintainer reviews a spec's PR, the next spec starts on a branch stacked on it, named `phase-N/spec-NNN`. It is rebased onto `main` once the first merges, and its PR opens then.
4. **Right-sized checks.**
   - Every change runs `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`.
   - `composer test:mutate` runs when `src/Engine` changes, and `composer test:drivers` when the stores or their use change. CI runs both anyway.
   - Benchmarks run when a change can move the request path's cost.
   - The review subagent reviews changes to `src/Engine`, to security-relevant code, and to the public API. Claude reviews other changes against their spec itself.
5. **Unchanged:** failing tests first, in their own commit; never editing tests during implementation; the hard rules; the CI gates; the maintainer merging every PR; and the stop at the end of each phase.

## Reasoning

- The maintainer reviews every PR anyway. Checking decisions there, instead of before the tests, removes one wait per spec and still checks them.
- Stacking removes the wait between specs. Rebasing a branch Claude created is cheap, and the maintainer never reviews a moving target, because each PR opens only when the one below it has merged.
- The checks cost the most when they cannot find anything. Mutation testing, for example, only covers `src/Engine`.

## Alternatives considered

- **Keep ADR-0003's loop as it is.** Rejected: the waits dominate.
- **Mini-specs in GitHub issues** (ADR-0003's fallback). Rejected for now: the specs are also the package's design record.
- **Claude merges green PRs.** Rejected: the maintainer keeps the merge.
- **One PR per phase.** Rejected: too big to review well.

## Fallback

- If vetoes become common, more than one spec in three reworked, go back to answering open questions before the tests.
- If stacked branches cause painful rebases, wait for the merge again.
- **Revisit when:** at the Phase 3 exit, compare the elapsed time per spec with Phase 2's.

## Consequences

- Good: fewer waits, and the maintainer reviews each spec once, in its PR.
- Bad: a veto costs more rework than an early answer would have.
- Bad: stacked work may need rebasing when the PR below it changes in review.

## Verification

- Each spec's PR lists its decisions and marks the ones that were Claude's recommendations.
- The Phase 3 exit review compares the elapsed time per spec with Phase 2's.

## Decision record

Accepted on 2026-09-28, at the Phase 2 exit review: the maintainer accepted the proposal as it stands, including pushes to stacked `phase-N/spec-NNN` branches.
