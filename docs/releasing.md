# Releasing

How a version goes out, and who hears about it when. The order of the stages comes from the roadmap's [release plan](roadmap.md), decided on 2026-10-17, and the versioning rules from [ADR-0017](adr/0017-versioning-and-release.md). This page is for the maintainer, so it stays off the documentation site.

## Stages

| Stage | Version | It goes out when | What may change | Who hears about it |
|---|---|---|---|---|
| Beta | `0.1.0-beta.N` | Fixes or a feature have landed, every one to two weeks | Anything; a breaking change gets an upgrade note in the changelog | A few places, to find testers |
| Release candidate | `0.1.0-RC1` | The gate below is met | Fixes only | Discussions, and the beta testers |
| Stable | `0.1.0` | No serious bug is reported for one to two weeks after the last RC | Nothing: it is the last RC, renamed | Everyone: the launch |
| 1.0 | `1.0.0` | ADR-0017's 1.0 criteria are met, including four weeks in a real app | Fixes, and what 1.0 needs | Everyone again |

**The gate for the release candidate:**
- No breaking change is still planned. Each roadmap item for the betas, such as SPEC-034, ships in a beta or moves after 0.1.0.
- No bug is open.
- The browser tests and a full run of the test sheet pass.
- At least a few people outside the project have run a beta, in staging or production, and their reports are fixed.

## Announcing

Each place hears about each stage once. The betas look for testers, and the launch waits for 0.1.0.

**The betas: find five to ten testers.** People who run it in staging and say what broke or confused them.
- **GitHub Discussions:** a pinned "Try the beta" post. It says what to try, such as dry-run on a real route, an emergency switch or a Redis outage, and where to report.
- **r/laravel:** a post that shows the GIF, says it is a beta, and asks for testers and feedback.
- **X, Bluesky and Mastodon:** a short post with the GIF and the documentation site.
- **People you know** who run Laravel in production: one tester in a real app is worth more than many stars.
- **Not Laravel News yet.** See below.

**0.1.0: the launch.**
- **Laravel News:** submit the documentation site in its [Links section](https://laravel-news.com/links/submit), on release day. Approved links go to its social accounts and are considered for its weekly newsletter.
- **A launch post** on a blog or dev.to: the problem (the README's "Why" table), the GIF, how to install, and the dashboard. The GitHub release links it.
- **r/laravel, X, Bluesky and Mastodon,** and an announcement in Discussions that thanks the beta testers.
- **Before the launch:**
  - The README installs with `^0.1`, without `@beta`.
  - The documentation site, the screenshots and the GIF show the current dashboard.
  - The Workbench demo starts with `docker compose up workbench`.

**1.0:** Laravel News and the same places again, with what changed since 0.1.0.

**Why Laravel News waits for 0.1.0:** its readers install on the day they read about a package, and a newsletter spot is a one-off. A beta needs `@beta` in `composer require`, its API may still change, and its first-impression bugs are not found yet. The betas' testers find those, so the launch's crowd meets a stable release.

## Each release, step by step

ADR-0017 plans a workflow that releases on a tag. Until it exists, these steps are done by hand.

1. **The release PR.** On a branch `release/X` from `main`, one commit, `chore: release X`, changes only the changelog:
   - The **Unreleased** entries move under `## [X] - YYYY-MM-DD`.
   - A short paragraph opens the version. It says what is in it and what an upgrade can trip over, such as a new health check that fails `--strict`.
   - The links at the bottom compare against the new tag.
2. **Merge** once CI is green.
3. **The GitHub release.** Publish it on github.com: Claude Code sessions cannot push tags (HTTP 403).
   - Releases, then **Draft a new release**, and the tag `vX`, created on publish.
   - Target the release PR's **merge commit**, not `main`, which may have moved since.
   - The title is `vX`. The notes are the version's changelog section, written for readers: the install command, "Before you upgrade", Added and Fixed.
   - Betas and release candidates are **pre-releases**.
4. **Packagist** lists the version within minutes, through its GitHub hook. Check [the package's page](https://packagist.org/packages/hanzala-afzal/laravel-dynamic-throttle), and click **Update** there if the version is missing.
5. **The documentation site** redeploys from `main` on its own, and its changelog page shows the new version.
6. **Announce** it as the stage says.

**A serious bug in a release:** fix it with a regression test first, then release the next beta, or `0.1.1` after 0.1.0, with the same steps the same day.
