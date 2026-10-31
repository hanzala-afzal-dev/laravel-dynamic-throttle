# ADR-0032: A develop branch, and a release branch for each release

- Status: Accepted
- Date: 2026-10-31
- Roadmap phase: after 0.1.0
- Amended: 2026-10-31, branches are named after their type of change (see the decision record)
- Amends: ADR-0027 (point 3: which branch pull requests target, and how stacked branches are named), ADR-0030 (which branch the build goes to), ADR-0017 (point 4: how a release is made), ADR-0016 (what a release's pull request tests)

## Context

- Until now, every pull request went to `main`. So `main` held changes that no release had shipped yet. GitHub's front page showed a README for features users could not install yet, and the docs site, which deploys from `main`, ran ahead of Packagist.
- The most thorough tests ran nightly, on whatever `main` was by then, not on what a release shipped.
- On 2026-10-31, the maintainer chose another way: `main` shows only the last release, its README, its docs and its changelog. Work collects in `develop`, and reaches `main` in a release, once every test has passed on it.

## Decision

1. **`develop` takes every pull request:** features, fixes, Dependabot's updates and the Build dist workflow's build. Branches start from `develop`, and stacked branches (ADR-0027) target it. A branch is named after its type of change, as its commits are: `feat/`, `fix/`, `docs/`, `ci/`, `chore/`, `refactor/` or `test/`, and a spec's stack is `feat/spec-NNN-M`.
2. **`main` takes only release branches.** For each release, a `release/<version>` branch starts from `develop`, moves the changelog's Unreleased entries under the version's heading, and opens a pull request into `main`. CI fails a pull request into `main` from any other branch.
3. **A release's pull request runs the integration run:**
   - the full matrix: PHP 8.3 to 8.5, Laravel 12 and 13, lowest and highest dependencies;
   - the driver tests on every store;
   - mutation testing of the whole engine;
   - installs into fresh Laravel 12 and 13 apps;
   - a check that `dist/` is what the sources build;
   - a browser test of the dashboard, once one exists (the maintainer asked for it on 2026-10-31).

   Other pull requests keep their smaller run (ADR-0016).
4. **A problem found on a release's pull request** is fixed on its release branch, by a pull request against that branch.
5. **After the release's pull request merges,** the maintainer tags `main` and publishes the GitHub release, and Packagist updates. Then a pull request from `main` into `develop` brings the release's changelog heading back to `develop`.
6. **No hotfix branches.** A fix for a released version ships through `develop` and the next release, with whatever else `develop` holds.
7. **`main` stays the default branch,** so GitHub's front page, Packagist's `dev-main` and the docs site show the last release. Dependabot reads its settings from the default branch, so they name `develop` as its target.
8. **The Build dist workflow builds after each push to `develop`,** and opens its pull request against `develop` (amends ADR-0030). A release branch starts from `develop`, so it already holds the build. Its pull request checks it.
9. **CI runs on pushes to `develop` and `main`,** with the full matrix, as it did on pushes to `main`. The docs site still deploys from `main`, so it shows the last release.

## Reasoning

- **`main` is what users have:** GitHub's front page, `dev-main` and the docs site never show a feature before its release.
- **One integration run per release, on what ships:** the slowest tests run once, on exactly the commit that is tagged, and every pull request stays fast.
- **Only through `develop`** (the maintainer's choice): one way for every change. A fix waits for the next release, but there is no second line of code to keep in step.
- **The build on `develop`:** a release then ships a `dist/` built before its tests ran, and its pull request fails if someone forgot the bot's last build, before the tag, not after it.

## Alternatives considered

- **Every pull request into `main`, as before.** Rejected: `main` ran ahead of the releases.
- **`develop` as the default branch.** Simpler for pull requests and Dependabot, but GitHub's front page would show unreleased work. Rejected by the maintainer.
- **Hotfix branches from `main`,** as git flow does. A fix could ship without `develop`'s other work. Rejected by the maintainer for now.

## Fallback

- If a fix users need waits too long on `develop`, add hotfix branches from `main`, merged back into `develop` after their release.
- If `develop` and `main` drift apart, because a back-merge was forgotten, add a CI check that `develop` holds every commit of `main`.
- **Revisit when:** the first fix has to ship without `develop`'s other work, or at 1.0.

## Consequences

- Good: `main` and the docs site always match the last release.
- Good: the slowest tests run on exactly what a release ships.
- Bad: one more long-lived branch, and a pull request back into `develop` after each release.
- Bad: Dependabot's settings reach `main` only with the next release. Until then, its pull requests open against `main`, and are moved to `develop` by hand.
- Bad: a fix ships only with the next release of everything in `develop`.

## Verification

- CI fails a pull request into `main` whose branch is not `release/<version>`.
- A release's pull request runs the full matrix and mutation testing of the whole engine, as the "Pick the test matrix" job's outputs show.
- A release's pull request fails when `dist/` is not what its sources build.

## Decision record

Chosen by the maintainer on 2026-10-31, in chat: a `develop` branch kept up to date; a release branch for each release, with `main` updated last; `main`'s README, docs and changelog showing only what is released; fixes only through `develop`; and full CI with a browser test on each release's pull request.

Amended on 2026-10-31, the same day: the maintainer chose to name branches after their type of change, as the commits are. `beta/<name>` dated from the betas, and `phase-N/spec-NNN-M` from the roadmap's phases.
