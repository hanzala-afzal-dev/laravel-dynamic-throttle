# Architecture Decision Records

This folder records the important decisions for `laravel-dynamic-throttle`: what we decided, why, what we rejected, and what we do if a decision turns out to be wrong.

## How to use these

- Every ADR has the same sections: **Context, Decision, Reasoning, Alternatives considered, Fallback, Consequences, Verification**.
- **Fallback** says what we switch to if the decision fails, and which signal tells us it failed ("Revisit when").
- **Verification** says how we prove the decision holds, usually a test, a benchmark or a CI check.
- Accepted ADRs are not rewritten. To change a decision, write a new ADR that supersedes the old one and set the old one's status to `Superseded by ADR-NNNN`. Fixing typos is fine.
- Specs in `docs/specs/` must follow the ADRs. If a spec needs to break an ADR, stop and write a new ADR first.

## Status values

`Proposed` → `Accepted` → `Superseded` or `Deprecated`

## Index

| ADR | Title | Status | Roadmap phase |
|---|---|---|---|
| [0001](0001-scope-and-positioning.md) | Scope and positioning | Accepted | 0 |
| [0002](0002-supported-platforms.md) | Supported PHP, Laravel and backends | Accepted | 0 |
| [0003](0003-development-method.md) | Development method: spec-first + test-first with Claude Code | Accepted | 0 |
| [0004](0004-request-pipeline.md) | Where the middleware runs | Accepted | 2 |
| [0005](0005-storage-abstraction.md) | Storage abstraction and drivers | Accepted | 1 |
| [0006](0006-rate-limit-algorithm.md) | Rate-limit algorithm, keys and time source | Accepted | 1 |
| [0007](0007-rule-storage-and-distribution.md) | Rule storage and distribution to servers | Accepted | 2 |
| [0008](0008-rule-matching.md) | Rule matching and stacking | Accepted | 2 |
| [0009](0009-scopes-and-identity.md) | Scopes and client identity | Accepted | 2 |
| [0010](0010-failure-policy.md) | Failure policy when storage breaks | Accepted | 2 |
| [0011](0011-emergency-controls.md) | Emergency controls | Accepted | 3 |
| [0012](0012-dry-run.md) | Dry-run semantics | Accepted | 2 |
| [0013](0013-telemetry.md) | Telemetry and metrics | Accepted | 3 |
| [0014](0014-dashboard-architecture.md) | Dashboard architecture | Superseded by 0018 | 4–5 |
| [0015](0015-dashboard-security-and-audit.md) | Dashboard security and audit log | Accepted | 4 |
| [0016](0016-testing-strategy.md) | Testing strategy | Accepted | 0–6 |
| [0017](0017-versioning-and-release.md) | Versioning and release | Accepted | 6 |
| [0018](0018-minimal-dashboard.md) | Minimal dashboard: Blade and vanilla JavaScript | Accepted | 4–5 |
| [0019](0019-tested-laravel-floors.md) | Declare only the Laravel versions CI tests | Accepted | 0 |
| [0020](0020-laravel-ecosystem-coexistence.md) | Coexistence with the Laravel ecosystem | Accepted | 2–3 |
| [0021](0021-compensation-dry-run-and-failed-release.md) | Compensation for dry-run rules and failed releases | Accepted | 2 |

## Template

```markdown
# ADR-NNNN: Title

- Status: Proposed
- Date: YYYY-MM-DD
- Roadmap phase: N
- Related: ADR-XXXX

## Context
What problem or force makes this decision necessary?

## Decision
What we will do. Concrete and testable.

## Reasoning
Why this option wins.

## Alternatives considered
Each option and why it lost.

## Fallback
What we switch to if this fails. "Revisit when": the signal that triggers it.

## Consequences
Good and bad results we accept.

## Verification
The test, benchmark or check that proves the decision holds.
```
