# ADR-0003: Development method — spec-first, test-first, with Claude Code

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0 (applies to every phase)
- Amended by: [ADR-0018](0018-minimal-dashboard.md) (no `dashboard/` folder or Vitest)

## Context

- One maintainer, with most code written through Claude Code.
- The domain is correctness-heavy: concurrency, time math, failure modes. Rate-limiting bugs are quiet. A limit that is slightly off or a race condition does not crash anything, so nobody notices until production.
- Claude Code's own guidance points two ways at once:
  - Claude stops when the work *looks* done, so it needs a check it can run (tests, build, linter).
  - For larger features, have Claude interview you and write a spec, then implement it in a fresh session.
- The question: TDD or spec-driven development?

## Decision

**Both, at different levels.** The spec says *what* and *why*. Tests prove it. Code comes last.

| Level | Artifact | Answers | Changes |
|---|---|---|---|
| 1 | ADRs (`docs/adr/`) | Why is the system shaped this way? | Rarely |
| 2 | Feature specs (`docs/specs/`) | What exactly should this feature do? | Once per feature |
| 3 | Tests (`tests/`) | Does the code do what the spec says? | With each spec |
| 4 | Code (`src/`, `dashboard/`) | How? | Freely, as long as tests stay green |

### The loop for every feature

1. **Spec (session A).** Prompt: *"Read docs/adr/README.md, the related ADRs and docs/specs/TEMPLATE.md. I want to build <feature>. Interview me about behavior, edge cases and failure modes. Then write docs/specs/NNN-name.md."* You edit the result. Acceptance criteria must use concrete numbers. Commit: `docs(spec): …`.
2. **Plan (session B, fresh, plan mode).** Prompt: *"Read @docs/specs/NNN-name.md. Explore src/ and tests/. Write an implementation plan: files to create or change, public interfaces, and a list of tests mapping each acceptance criterion (AC-n) to a test."* You approve or edit the plan.
3. **Red.** Prompt: *"Write the Pest tests from the plan. Do not write implementation code. Run them and show me the failing assertions."* The tests must fail because the behavior is missing, not because the test itself is broken. **You review the tests carefully. This is the most important human checkpoint.** Commit: `test: …`.
4. **Green.** Prompt: *"Implement until all tests pass. Do not edit anything under tests/. If a test looks wrong, stop and explain why."* Then run `composer test`, `composer analyse` and `composer format`, and show the output as evidence.
5. **Refactor.** Prompt: *"Refactor for clarity without changing behavior. Tests must stay green."*
6. **Review.** Prompt: *"Use a subagent to review the diff against docs/specs/NNN-name.md. Report only gaps that affect correctness or the acceptance criteria."* Fix what matters, then commit and open a PR.
7. **Close.** Set the spec status to `Done`. If implementation changed a decision, write a new ADR that supersedes the old one.

### When steps can be skipped

- **Tiny changes** (you can describe the diff in one sentence): skip the spec and the plan, but still run tests.
- **Bug fixes:** always start with a failing regression test, even without a spec.
- **Spikes** (e.g. "how fast is the Lua script?"): throwaway branch `spike/*`, no tests. Write the findings into the spec, then delete the branch.
- **UI looks:** Vitest for component logic; screenshots compared against a design for appearance.

### Guardrails that make the method stick

Deterministic checks beat instructions Claude might forget:

- `CLAUDE.md` holds the workflow rules, kept short.
- A Claude Code **Stop hook** runs the tests and static analysis and blocks the turn from ending while they fail. Check the hooks documentation for the exact exit-code contract.
- CI checks are required before merging.
- Tests and implementation go in separate commits, so any change to `tests/` during implementation is visible in review.
- Mutation testing on core code catches weak tests that pass no matter what.
- Test names start with the criterion id (`it('AC-3: …')`), so every acceptance criterion traces to a test.

## Reasoning

- **Why not pure TDD.** Tests encode behavior, but not decisions such as fail-open vs. fail-closed or how stacked rules interact. Without a spec, Claude invents those requirements while writing tests. Tests written in the same breath as the code also tend to confirm the code instead of challenging it.
- **Why not pure spec-driven.** Prose can be read more than one way; an assertion cannot. Without executable checks, "done" means "looks done", and quiet bugs like an off-by-one limit slip through.
- **Why both fit Claude Code.** The spec gives the planning session full context. The tests give the implementation session a pass/fail loop it can run alone. Fresh sessions per step keep the context window clean.
- **Why the human checkpoint is at the tests, not the code.** Reviewing 30 lines of assertions is faster and more valuable than reviewing 300 lines of implementation. If the tests are right and green, the code is at least correct.

## Alternatives considered

- **Pure TDD.** Good for small units, weak for cross-cutting semantics. Rejected as the only method.
- **Heavy spec-driven toolkits** (generated requirement/design/task files). More ceremony than a solo project needs; plain markdown specs give the same benefit. Can be adopted later.
- **BDD with Gherkin/Behat.** Given/When/Then acceptance criteria inside the spec give the benefit without the extra tooling.
- **Unstructured prompting ("vibe coding").** Fast at first, but unfit for concurrency and security code. Rejected.

## Fallback

- **Specs take too long for small features** (roughly more than 30% of the feature's time): switch to mini-specs, meaning acceptance criteria only, written in the GitHub issue.
- **Claude keeps editing tests to make them pass:** add a permission deny rule or hook that blocks writes to `tests/**` during implementation sessions.
- **Tests pass but bugs show up under load:** extend the concurrency and model-based tests (ADR-0016) rather than adding more example tests.
- **Specs get too big for one session:** split them so each spec is at most about a day of work.
- **Revisit when:** after Phase 2, compare the time spent per phase and the number of bugs found after merge.

## Consequences

- Good: specs double as documentation, git history reads like a story, and regressions are caught early.
- Bad: more writing up front, and discipline is needed to stop at the Red step.

## Verification

- Every merged feature has a spec with acceptance criteria.
- Every acceptance criterion id appears in at least one test name. A small CI script can check this.
- The mutation score on `src/Engine` stays at or above the threshold set in ADR-0016.
