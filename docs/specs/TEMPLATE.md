# SPEC-NNN: <Feature name>

- Status: Draft | Approved | In progress | Done
- ADRs: ADR-00XX, ADR-00YY
- Roadmap phase: N

## Problem
Two or three sentences. What is missing or broken, and for whom?

## Behavior
What the feature does, in plain words. No implementation details unless an ADR requires them.

## Acceptance criteria
Concrete numbers only. Each criterion gets an id; test names start with it (`it('AC-1: …')`).

- **AC-1** Given …, when …, then ….
- **AC-2** Given …, when …, then ….

## Interfaces and files
New or changed classes, methods, config keys, commands, migrations. Name the files.

## Out of scope
What this spec deliberately does not do (and which spec will).

## Edge cases and failure modes
Empty input, boundaries, concurrency, storage failure, clock edges.

## End-to-end verification
Exact commands that prove the feature works, for example:
`composer test -- --filter=…`, `composer analyse`.

## Open questions
Anything to resolve before the status can move to Approved.
