# ADR-0017: Versioning and release

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 6
- Related: ADR-0002, ADR-0014
- Amended by: [ADR-0018](0018-minimal-dashboard.md) (no `dist/` build or freshness check)

## Context

Users need to know what they can rely on across updates, and a solo maintainer needs a release process that is cheap to repeat.

## Decision

1. **Semantic Versioning.** Stay in `0.x` until the 1.0 criteria below are met. In 0.x, minor versions may contain breaking changes, which the changelog calls out.
2. **Public API (covered by SemVer from 1.0):**
   - config keys
   - `LimiterStore` and scope-resolver contracts
   - events
   - Artisan commands and options
   - HTTP response headers
   - the facade
   - database schema changes (only through **new** migrations; published migrations are never edited)
3. **Not public:** the dashboard JSON API (until declared stable), internal classes marked `@internal`, and everything under `src/Engine/Internal`.
4. **Process:**
   - Conventional commits, so Claude Code writes the commit messages in that format.
   - A `CHANGELOG.md` in Keep-a-Changelog style.
   - Git tags trigger the release workflow: tests, building `dist/`, a GitHub release, and a Packagist update through a webhook.
5. **Docs per release:** an upgrade guide for every breaking change, and `SECURITY.md` with private reporting.
6. **1.0 criteria:**
   - All roadmap phase exit criteria are met.
   - The package has run in at least one real app for 4 weeks.
   - There are no known correctness bugs.
   - The docs cover install, every scope, every driver's guarantees, the runbook and the comparison page.

## Reasoning

- A clear public-API list tells contributors what needs a major version bump, and tells users what to rely on.
- A tag-triggered release keeps releasing boring and repeatable.
- Real-app dogfooding before 1.0 finds the problems tests cannot.

## Alternatives considered

- **Calendar versioning.** Says nothing about compatibility. Rejected.
- **Going to 1.0 immediately.** Locks in APIs before real feedback. Rejected.

## Fallback

- If a breaking schema change is unavoidable after 1.0, ship a new migration plus an upgrade command, in a major version.
- If 1.0 keeps slipping because of the dogfooding requirement, publish a 1.0 release candidate and collect feedback from early users instead.
- **Revisit when:** preparing 1.0.

## Consequences

- Good: predictable upgrades for users.
- Bad: discipline is needed around `@internal` markers and migrations.

## Verification

- The release workflow fails if the changelog entry is missing, `dist/` is stale or tests fail.
- A PR template checklist asks: "Does this change the public API?"
