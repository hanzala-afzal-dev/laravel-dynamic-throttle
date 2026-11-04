# ADR-0030: The dashboard's build reaches main in a bot pull request

- Status: Accepted
- Date: 2026-10-30
- Roadmap phase: after 0.1.0
- Amends: ADR-0028 (point 2: who commits `dist/`, and how CI checks it)
- Amended: 2026-10-31 by [ADR-0032](0032-develop-and-release-branches.md): the build goes to `develop` and to release branches, not `main`, and a release's pull request checks it.

## Context

- ADR-0028 ships the dashboard's build, `dist/`, in the package. Composer installs from a tag's tree, so the tag must hold the build. Until now, each pull request that changed the dashboard committed its own build, and CI checked that it was what the sources build.
- Every file name in the build holds a hash of its content, so two pull requests that change the dashboard always conflict in `dist/`: the second to merge needs `main` merged in and the build redone. It happened on 2026-10-29 with #157 and #158. A build in each pull request also adds dozens of renamed files to every dashboard review.
- `main` is protected by a ruleset that requires a pull request for every change and lets nobody bypass it, so a workflow cannot push to it.

## Decision

1. **Pull requests leave `dist/` alone.** CI builds the sources on every pull request, to prove they build and stay within the budget, and fails one that changes `dist/`.
2. **After each push to `main`, the Build dist workflow builds the sources.** When the build differs from `main`'s `dist/`, it force-pushes it to the branch `bot/build-dist`, which only the workflow writes, and opens a pull request, "chore: build dist", or updates the open one. The maintainer merges it like any other.
3. **It runs on every push to `main`, not only on dashboard changes.** The build depends on more than `resources/dashboard/`, such as the npm lock file and the Vite and TypeScript configs, and a list of paths to watch could miss one. It opens a pull request only when the build differs.
4. **Releases wait for it.** Before publishing a release, merge the open "chore: build dist" pull request, if there is one. When a release is published, the workflow builds its tag's sources and fails, saying what to do, if the tag's `dist/` differs.

## Reasoning

- A pull request from the workflow keeps `main`'s ruleset as it is: no bypass and no secret.
- One bot branch, rebuilt from `main` each time, never conflicts, whatever merged in between.

## Alternatives considered

- **A deploy key in a secret, with a ruleset bypass, pushing the build to `main`.** One pull request fewer per dashboard change, but a key with write access in the repository's secrets, and a hole in the ruleset. Rejected by the maintainer.
- **Building only at release.** No bot pull requests, but `main`'s `dist/` would trail the sources between releases, and releasing would move from GitHub's page to a workflow. Rejected.
- **Keeping ADR-0028's build in each pull request.** Rejected for the conflicts and the review noise above.

## Consequences

- Good: a dashboard pull request holds only its sources, and two of them no longer conflict in `dist/`.
- Bad: one more pull request to merge after a dashboard change. Until it merges, `main`'s `dist/` trails its sources, so the Docker demo and an install of `dev-main` show the previous dashboard.
- Bad: a pull request opened with the workflow's own token starts no workflows, so CI does not run on the bot's pull request. It changes only `dist/`, which the workflow built from `main`'s sources, as CI would have.
- Bad: it needs a repository setting: Settings → Actions → General → "Allow GitHub Actions to create and approve pull requests".

## Verification

- CI fails a pull request that changes `dist/`, other than the bot's, and checks that the sources build within the budget.
- The Build dist workflow checks each published release: its tag's `dist/` is what its sources build.

## Decision record

Decided by the maintainer on 2026-10-30. They chose a build after merge over a build in each pull request, and, since `main`'s ruleset lets no workflow push to it, a pull request from the workflow over a deploy key with a bypass.
