# ADR-0033: The README, the docs and the changelog are for users only

- Status: Accepted
- Date: 2026-10-31
- Roadmap phase: after 0.1.0
- Amends: ADR-0029 (point 2: guide pages no longer link to ADRs or specs), ADR-0017 (point 4: what the changelog lists)

## Context

- ADR-0029 already keeps the ADRs, the specs and the roadmap off the docs site. But the guide's pages still cited ADRs and specs in about 20 places, the performance page told readers how to run the repository's benchmarks, and the changelog linked specs and listed changes to the docs site itself.
- A user reads the README, the docs site and the changelog to install the package, use it, and see what a release brings. Design records, tests, CI and git workflow are for the people who develop the package.
- On 2026-10-31, the maintainer asked that public release notes show only bug fixes and what is new, and that nothing about development, testing or git appear in the docs, the guide or the README.

## Decision

1. **Public files speak to users only:** `README.md`, `docs/guide/`, `docs/runbook.md`, `docs/site/` and `CHANGELOG.md`. They say what the package does, how to set it up and use it, what to do when something goes wrong, what is new and what is fixed.
2. **They hold nothing about developing the package,** and link to none of it: no ADRs, specs, roadmap, tests, CI, benchmarks' commands, branches or pull requests. A guide page states a decision's effect in its own words instead of citing the ADR. Comments in a page's source, which readers never see, may still name an ADR.
3. **That content lives in the developer files:** `CONTRIBUTING.md`, `CLAUDE.md`, `docs/adr/`, `docs/specs/`, `docs/roadmap.md` and `benchmarks/`.
4. **The changelog lists what users notice,** under **Added** and **Fixed**. A release that needs a change in an app says what to do in its first paragraph: that is its upgrade note (ADR-0017). Dependency updates, CI, tests, the docs site and the repository's own changes get no entry.
5. **Exceptions a user needs:** testing *their* app (`DynamicThrottle::fake()`), the support policy, measured performance, and where to report a bug or a security issue.

## Reasoning

- Users came for the package, not its design process: every link to a spec is one more thing that looks like required reading.
- A guide that states the effect ("the rules are compiled into your cache") is clearer than one that sends the reader to a design record.
- Only Added and Fixed make a release easy to scan, and the first paragraph puts an upgrade step where nobody can miss it.

## Alternatives considered

- **Keep ADR links in the guide, opened on GitHub** (ADR-0029's amendment). Rejected by the maintainer: they lead users into developer material.
- **Keep Keep a Changelog's full list of sections** (Changed, Deprecated, Removed, Security). Rejected: the maintainer asked for fixes and new things only. A breaking change still gets its upgrade note, in the release's first paragraph, and a security fix is listed under Fixed.

## Fallback

- If users miss the reasons behind a behavior, add a short "Why" paragraph to the guide page, in users' words, never a link to an ADR.
- **Revisit when:** a release has a change that is neither new nor a fix and does not fit in its first paragraph.

## Consequences

- Good: the README, the docs site and the changelog read as a product's docs.
- Bad: a guide page and its ADR say the same thing in two places, so a change of decision updates both.

## Verification

- A review of each pull request that changes a public file, against point 2.
- `grep` finds no link to `docs/adr/`, `docs/specs/` or `benchmarks/` in the public files.

## Decision record

Chosen by the maintainer on 2026-10-31, in chat: public release changelogs show only bug fixes and what is new, and anything about development, testing and git stays out of the documentation, the guide and the README.
