# Contributing

Thanks for helping. Rate-limiting bugs are quiet: an off-by-one limit or a race condition crashes nothing, so nobody notices until production. The workflow below exists to catch those bugs early.

## Before you start

- Read the [ADR index](docs/adr/README.md). ADRs record why the package is shaped the way it is.
- For anything bigger than a one-sentence change, open an issue first so we can agree on the behavior.
- Security problems go through [SECURITY.md](SECURITY.md), never through public issues.

## Workflow ([ADR-0003](docs/adr/0003-development-method.md))

1. **Spec.** Features start with a spec in `docs/specs/` (use `TEMPLATE.md`). Acceptance criteria use concrete numbers.
2. **Failing tests.** Write the Pest tests first and commit them on their own (`test: …`). Test names start with the criterion id: `it('AC-3: …')`.
3. **Implementation.** Make the tests pass without editing them. If a test looks wrong, say so in the PR instead of changing it quietly.
4. **Checks.** All of these must pass:

   ```bash
   composer test
   composer analyse
   composer format:check
   composer refactor:check
   ```

   A change to the dashboard also runs its own (see below).

Tiny changes can skip the spec. Bug fixes always start with a failing regression test.

If a spec would break an ADR, write a new ADR that supersedes the old one first.

## Branches and releases ([ADR-0032](docs/adr/0032-develop-and-release-branches.md))

`main` holds the last release: its README, its docs and its changelog. The work collects in `develop`.

- **Pull requests go to `develop`.** Start your branch from `develop`, and open the pull request against it. CI fails a pull request into `main` unless its branch is `release/<version>`.
- **A release goes through a release branch:**
  1. Merge `develop`'s open "chore: build dist" pull request, if there is one.
  2. Start `release/<version>` from `develop`. In `CHANGELOG.md`, move the Unreleased entries under a heading with the version and the day you will tag it. Open a pull request into `main`.
  3. CI runs the integration run on it: the full matrix, the driver tests on every store, mutation testing of the whole engine, installs into fresh Laravel 12 and 13 apps, and a check that `dist/` is what the sources build.
  4. Fix any problem on the release branch, with a pull request against it.
  5. Once it is green, the maintainer merges it, tags the merge commit on `main` as `v<version>`, and publishes the GitHub release. Packagist updates on its own.
  6. Open a pull request from `main` into `develop`, so `develop` gets the release's changelog heading.
- **There are no hotfix branches:** a fix for a released version ships with the next release from `develop`.

## Local setup

The test suite runs in Docker, so you do not need PHP 8.3 installed:

```bash
docker compose run --rm php composer update
docker compose run --rm php composer test
```

Driver tests need the backing services:

```bash
docker compose up -d
docker compose run --rm php composer test:drivers
docker compose down -v
```

`docker compose run --rm php scripts/verify-install.sh 13` installs the package into a fresh Laravel app and checks that it boots.

## Style

- PHP 8.3 syntax, `declare(strict_types=1)` everywhere, final classes by default, readonly value objects, and backed enums instead of string constants.
- `tests/Arch` enforces several hard rules: no database access on the request path, no direct clock reads in the engine, no insecure functions. Never weaken an arch test to make code pass.
- Commits follow [Conventional Commits](https://www.conventionalcommits.org/): `feat:`, `fix:`, `test:`, `docs:`, `refactor:`, `ci:`, `chore:`.
- User-facing changes get an entry in [CHANGELOG.md](CHANGELOG.md).

## Public API

These are covered by Semantic Versioning from 1.0 ([ADR-0017](docs/adr/0017-versioning-and-release.md)):
- config keys
- contracts
- events
- Artisan commands
- HTTP headers
- the facade
- the database schema

A PR that changes any of them must say so, and a breaking change needs an upgrade note. Published migrations are never edited. Schema changes ship as new migrations.

## Working on the dashboard ([ADR-0028](docs/adr/0028-dashboard-react-shadcn.md), [ADR-0030](docs/adr/0030-dist-built-after-merge.md))

The dashboard is React with shadcn/ui, in `resources/dashboard/`. Vite builds it into `dist/`, which is committed: users get the build and install nothing. Pull requests leave `dist/` alone: after each merge into `develop`, the Build dist workflow builds it and opens a pull request with it, "chore: build dist". You need Node 22.

```bash
npm ci
npm run build       # writes dist/, to try your change: leave it out of your commit
npm run test        # Vitest
npm run typecheck
npm run lint
npm run budget      # the first page stays under 300 KB gzipped
```

`npm run dev` rebuilds on every change. Both rewrite `dist/` in your checkout, so put it back before you commit: `git checkout -- dist && git clean -fdq dist`. CI fails a pull request that changes `dist/`, and never edit it by hand. Add shadcn/ui components by copying them into `components/ui/`.

### Trying the dashboard

The Workbench app is a demo of the dashboard. It has four rules, an exemption, a week of made-up hourly numbers, and routes for the rules to limit. Node is not needed: `dist/` is committed.

With Docker, which counts in Redis as production would:

```bash
docker compose up workbench
```

Then open <http://127.0.0.1:8000/dynamic-throttle>. Each start seeds the demo afresh and sends a minute of traffic. The scheduler runs, so the day and week charts gain the current hour. For more traffic, from another terminal:

```bash
docker compose exec workbench vendor/bin/testbench demo:traffic --minutes=5
```

With PHP 8.3 or later installed instead, `composer serve` builds, seeds and serves the same demo at <http://127.0.0.1:8000/dynamic-throttle>, counting in the database cache store. Send traffic with `vendor/bin/testbench demo:traffic`. Offenders need Redis, so they show only in the Docker demo.

As a guest you are a manager. To see the dashboard as a viewer, open `/_workbench/login/viewer@example.com`. `/_workbench/logout` signs you out again.

After pulling changes, restart the demo. `npm run build` changes take effect on the next page load.

## The documentation site ([ADR-0029](docs/adr/0029-documentation-site.md))

The Markdown in `docs/` is also a website, built with VitePress and served by GitHub Pages at <https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/>. Write the docs for GitHub, with links relative to their file: the site sends a link to one of its pages to that page, and any other link to the file on GitHub. The site is for the package's users: the ADRs, the specs and the roadmap stay on GitHub only, and a link to one of them opens it there.

```bash
cd docs
npm ci
npm run dev      # http://localhost:5173/laravel-dynamic-throttle/
npm run build    # fails on a link to a missing file, page or heading
```

The home page and Getting started show sections of the README between `<!-- #region … -->` comments, so keep those comments when you edit it. The site's own pages are in `docs/site/`, and its sidebar lists the guide's pages in the order of `docs/guide/README.md`. Pages show images from `art/` by importing them as `@art/…`.

`docs/package.json` overrides VitePress's Vite with 6.4.5. VitePress 1.6.4, its latest release, brings Vite 5, whose development server has known advisories that no Vite 5 release fixes. They affect `npm run dev` only, never the published site. Remove the override once a VitePress release brings a fixed Vite.

## What users download

`composer require` installs only the package: `src/`, `config/`, `database/`, `resources/views/`, `dist/`, `composer.json`, and the README, LICENSE, CHANGELOG and SECURITY files. Everything else in this repository is for development and stays out through `export-ignore` in `.gitattributes`: tests, docs, scripts, Docker and CI files, the dashboard's sources and toolchain, and `CLAUDE.md`. A CI check fails when any other file would ship. When you add a new top-level file or folder, add it to `.gitattributes` unless users need it.

## Claude Code configuration

`.claude/settings.json` registers a Stop hook that runs `.claude/hooks/verify.sh` on the machine of anyone who opens this repository in Claude Code. The hook runs the test suite, so it executes the code in the checked-out branch. Treat changes to `.claude/`, `.github/` and `scripts/` like changes to CI, and review them carefully. Review pull requests from forks in CI or with `gh pr diff` instead of checking them out in a Claude Code session. If you do check one out, set `"disableAllHooks": true` in `.claude/settings.local.json` first.

## Licensing of contributions

By submitting a contribution, you agree that it is licensed under this project's [MIT License](LICENSE) (inbound = outbound). You also confirm that you have the right to submit it: it is your own work, or it comes from a source whose license is compatible with MIT and is credited in the PR. Do not copy code from projects with incompatible licenses.

## Code of conduct

This project follows its [Code of Conduct](CODE_OF_CONDUCT.md). By taking part, you agree to follow it.
