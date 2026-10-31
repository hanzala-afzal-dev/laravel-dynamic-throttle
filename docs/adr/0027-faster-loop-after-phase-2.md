# ADR-0027: A faster loop after Phase 2

- Status: Accepted
- Date: 2026-09-28
- Roadmap phase: 3 onward
- Amends: [ADR-0003](0003-development-method.md) (the loop for every feature)
- Amended: 2026-10-07, a spec ships as small stacked PRs, and benchmark results without their raw dumps (see the decision record)
- Amended: 2026-10-17, every PR targets `main`, its branch still stacked on the one below (see the decision record)
- Amended: 2026-10-31 by [ADR-0032](0032-develop-and-release-branches.md): every PR targets `develop` instead of `main`, its branch still stacked on the one below and named `feat/spec-NNN-M`, and `main` takes only release branches.

## Context

- ADR-0003 asked to revisit the loop after Phase 2, comparing the time spent per phase.
- In Phase 2, most of a spec's elapsed time was waiting, not work:
  - Each spec waited for the maintainer's answers to its open questions before its tests. The maintainer accepted every recommendation for SPEC-011 and SPEC-012, and nearly all earlier ones.
  - Each spec waited for the previous PR to merge before it started.
- The checks were worth their cost: every spec's review or mutation run found real defects. For example, SPEC-012's review found that one app's outage put every other app on the server into the cooldown.

## Decision

1. **Recommendations are the default.** A spec's open questions still get options and a recommendation. Claude records the recommendation as the decision and goes on to the tests and the code. The maintainer vetoes decisions in the PR review, and a veto is reworked in the PRs whose code it changes.
   - Claude still stops and asks first when a question conflicts with an ADR, when it fixes a public API that would be hard to change after a release, or when no option is clearly best.
   - The first PR of each spec lists its decisions, so the maintainer can veto them.
2. **Small PRs.** A spec ships as several PRs of about 300 to 600 lines of `src` each, with their tests. Each PR names the acceptance criteria it covers and is green on its own.
   - Each keeps ADR-0003's order: its failing tests in a commit before its code, then the review's fixes.
   - The first PR carries the spec and its decisions, so there is no PR for the spec alone. The last closes the spec.
   - A spec small enough for one such PR ships as one.
3. **Stacked branches, opened at once.** Each PR opens as soon as it is green, on a branch stacked on the one below it, named `phase-N/spec-NNN-M`. The next spec starts stacked on the last PR of the one before it.
   - The maintainer reviews the stack from the bottom.
   - When a PR merges, the next one's base switches to `main`. `main` merges with merge commits, so that PR then shows only its own commits, with no rebase or force push.
   - A change asked for in a lower PR is made there and merged up into the PRs above it.
4. **Right-sized checks, per PR.**
   - Every PR runs `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`.
   - `composer test:mutate` runs when `src/Engine` changes, and `composer test:drivers` when the stores or their use change. CI runs both anyway.
   - Benchmarks run when a change can move the request path's cost. The PR commits their summary, with the hardware stated (ADR-0016), and not PHPBench's raw XML.
   - The review subagent reviews changes to `src/Engine`, to security-relevant code, and to the public API. Claude reviews other changes against their spec itself.
5. **Unchanged:** failing tests first, in their own commit; never editing tests during implementation; the hard rules; the CI gates; the maintainer merging every PR; and the stop at the end of each phase.

## Reasoning

- The maintainer reviews every PR anyway. Checking decisions there, instead of before the tests, removes one wait per spec and still checks them.
- Small PRs are quicker to review, and to review well, than a whole spec at once.
- Stacking removes the wait between PRs. Merging review fixes up the stack keeps each PR's diff to its own changes, so the maintainer never reviews a moving target.
- The checks cost the most when they cannot find anything. Mutation testing, for example, only covers `src/Engine`.

## Alternatives considered

- **Keep ADR-0003's loop as it is.** Rejected: the waits dominate.
- **Mini-specs in GitHub issues** (ADR-0003's fallback). Rejected for now: the specs are also the package's design record.
- **Claude merges green PRs.** Rejected: the maintainer keeps the merge.
- **One PR per phase.** Rejected: too big to review well.

## Fallback

- If vetoes become common, more than one spec in three reworked, go back to answering open questions before the tests.
- If stacked branches cause painful merges, wait for the merge again.
- **Revisit when:** at the Phase 3 exit, compare the elapsed time per spec with Phase 2's.

## Consequences

- Good: fewer waits, and the maintainer reviews each part of a spec once, in its PR.
- Bad: a veto costs more rework than an early answer would have.
- Bad: a change made in a lower PR's review has to be merged up into the PRs above it.
- Bad: the stack relies on merge commits. A squash merge would need the PRs above it rebased.

## Verification

- The first PR of each spec lists its decisions and marks the ones that were Claude's recommendations.
- Each PR names the acceptance criteria it covers.
- The Phase 3 exit review compares the elapsed time per spec with Phase 2's.

## Decision record

Accepted on 2026-09-28, at the Phase 2 exit review: the maintainer accepted the proposal as it stands, including pushes to stacked `phase-N/spec-NNN` branches.

Amended on 2026-10-07, after SPEC-014's PR reached about 6,000 added lines, and the branches of SPEC-015 and SPEC-016, built but not yet opened, about 11,300 and 4,400. PHPBench's raw XML was 2,600 of SPEC-014's lines and 6,400 of SPEC-015's. The maintainer asked for smaller PRs to speed up the reviews, and Copilot's code review, which is metered by what it reads, had stopped at the maintainer's quota. The maintainer accepted the recommendation:
- Point 2: a spec ships as small PRs of about 300 to 600 lines of `src` each, with their tests. SPEC-015 is re-cut into three PRs and SPEC-016 into five. SPEC-014's PR stays as it is.
- Point 3: the PRs open as soon as they are green, stacked, and the next one's base switches to `main` when one merges.
- Point 4: the checks run per PR, and a benchmark's raw XML stays out of the repository. The dumps already committed stay.

Amended on 2026-10-18, when the repository went public: the dumps committed before 2026-10-07 are removed too, as the maintainer accepted. They added 16 files that nothing reads to the public tree, and git history keeps them. Each summary in `benchmarks/results/` says how to find its dump, and `.gitignore` keeps new ones out.
- `.github/copilot-instructions.md` asks Copilot to read the PR's spec and the ADRs it cites, rather than all of `docs/` and the changelog.

Amended on 2026-10-17, with Phase 5's 29 stacked PRs open: switching each PR's base to `main` after the one below it merged was a chore for every merge. The maintainer asked for every PR to target `main`:
- Point 3: each PR opens against `main`, on a branch still stacked on the one below. Its diff shows the PRs below it too until they merge, so they merge oldest first, or the top one alone, which carries them all.
