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

## Working on the dashboard ([ADR-0028](docs/adr/0028-dashboard-react-shadcn.md))

The dashboard is React with shadcn/ui, in `resources/dashboard/`. Vite builds it into `dist/`, which is committed: users get the build and install nothing. You need Node 22.

```bash
npm ci
npm run build       # writes dist/: commit it with your change
npm run test        # Vitest
npm run typecheck
npm run lint
npm run budget      # the first page stays under 300 KB gzipped
```

`npm run dev` rebuilds on every change. CI fails when `dist/` is not what the sources build, so never edit it by hand. Add shadcn/ui components by copying them into `components/ui/`.

### Browser tests

The dashboard's browser tests run it in Chromium with Playwright, against the Workbench app on Redis:

```bash
docker compose up -d redis
npx playwright install chromium   # once
npm run e2e
```

Each run builds a fresh Workbench database and starts its own server on port 8123 (`E2E_PORT` picks another). A failed test keeps a screenshot and a trace in `test-results/`, which `npx playwright show-trace <file>` replays.

On Windows, run them in WSL 2, with the repository in WSL's own file system, such as `~/laravel-dynamic-throttle`, not under `/mnt/c`. PHP's built-in server cannot fork workers on Windows, so it answers one request at a time, and the tests time out: the run stops with a message that says so. `E2E_ALLOW_WINDOWS=1` runs them there anyway.

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

The Markdown in `docs/` is also a website, built with VitePress and served by GitHub Pages at <https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/>. Write the docs for GitHub, with links relative to their file: the site sends a link to one of its pages to that page, and any other link to the file on GitHub. The specs and the roadmap stay on GitHub only.

```bash
cd docs
npm ci
npm run dev      # http://localhost:5173/laravel-dynamic-throttle/
npm run build    # fails on a link to a missing file, page or heading
```

The home page and Getting started show sections of the README between `<!-- #region … -->` comments, so keep those comments when you edit it. The site's own pages are in `docs/site/`, and its sidebar lists the guide's pages in the order of `docs/guide/README.md`.

## What users download

`composer require` installs only the package: `src/`, `config/`, `database/`, `resources/views/`, `dist/`, `composer.json`, and the README, LICENSE, CHANGELOG and SECURITY files. Everything else in this repository is for development and stays out through `export-ignore` in `.gitattributes`: tests, docs, scripts, Docker and CI files, the dashboard's sources and toolchain, and `CLAUDE.md`. A CI check fails when any other file would ship. When you add a new top-level file or folder, add it to `.gitattributes` unless users need it.

## Claude Code configuration

`.claude/settings.json` registers a Stop hook that runs `.claude/hooks/verify.sh` on the machine of anyone who opens this repository in Claude Code. The hook runs the test suite, so it executes the code in the checked-out branch. Treat changes to `.claude/`, `.github/` and `scripts/` like changes to CI, and review them carefully. Review pull requests from forks in CI or with `gh pr diff` instead of checking them out in a Claude Code session. If you do check one out, set `"disableAllHooks": true` in `.claude/settings.local.json` first.

## Licensing of contributions

By submitting a contribution, you agree that it is licensed under this project's [MIT License](LICENSE) (inbound = outbound). You also confirm that you have the right to submit it: it is your own work, or it comes from a source whose license is compatible with MIT and is credited in the PR. Do not copy code from projects with incompatible licenses.

## Code of conduct

This project follows its [Code of Conduct](CODE_OF_CONDUCT.md). By taking part, you agree to follow it.
