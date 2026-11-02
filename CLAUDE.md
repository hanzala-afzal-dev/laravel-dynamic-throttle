# laravel-dynamic-throttle

Laravel package: runtime-managed, identity-aware rate limiting with dry-run and a minimal dashboard.
Composer name `hanzala-afzal/laravel-dynamic-throttle`, namespace `Afzal\DynamicThrottle`, MIT license.
Decisions live in docs/adr/ (index: docs/adr/README.md). Feature specs live in docs/specs/. The roadmap is docs/roadmap.md.

## Commands
- `composer test`: Pest. Single test: `composer test -- --filter=Name`
- `composer analyse`: Larastan
- `composer format`: Pint
- `composer refactor`: Rector
- `composer test:mutate`: mutation testing of everything in `src/Engine` with the service suites (needs `docker compose up -d`), fails below 85% (ADR-0016). Never pass `--filter` to `--mutate`: it reports fake kills. Use `--class=...` or `--path=...` to narrow the run.
- `covers()` also decides what mutation testing counts as covered: a test only covers the classes it lists, not the code they call. Give every class in `src/Engine` a test that lists it, or its mutants show as uncovered.
- Never write `->not->toThrow(Throwable::class)` (or any interface): Pest reads an interface name as a message, so it passes for any `Error`. Assert what the code did instead.
- Tests must be safe under `--parallel`, which mutation testing uses. Never write into Testbench's shared skeleton, and never rely on server-wide Redis stats. Tag slow multi-process tests `->group('concurrency')`.
- `composer test:drivers`: the driver tests plus the LimiterStore contract suite against every docker compose service
- `composer bench`: PHPBench (needs `docker compose up -d`). Recorded results go in benchmarks/results/ as a summary with the hardware stated (ADR-0016). Never commit PHPBench's raw XML (ADR-0027).
- Cloud sessions (claude.ai/code, where this project now runs): PHP and Composer are native, so run `composer …` directly. The image has had PHP 8.4 and, since 2026-10-24, Ubuntu's PHP 8.3; CI covers 8.3 to 8.5. A SessionStart hook (`scripts/cloud-session-start.sh`) builds missing PHP extensions, rebuilds phpredis when apt's is older than 6 (the tests' Redis doubles need 6), installs the dependencies and starts the services with Docker. `scripts/cloud-setup.sh` is the environment's setup script.
- `scripts/ci-local.sh` runs CI's checks locally (`--drivers` adds the stores, `--mutate PATHS` mutation testing). Use it when GitHub Actions cannot run, and say so in the PR. The repository is public since 2026-10-18, so Actions minutes are free.
- Local machine only (it has PHP 8.2): prefix with `docker compose run --rm php`, e.g. `docker compose run --rm php composer test`
- `docker compose up -d`: Redis, Valkey, Memcached, MySQL, PostgreSQL for driver tests (`composer test:drivers`)
- Dashboard (ADR-0028, ADR-0030): `npm ci` once, then `npm run build` (writes `dist/` to try a change: never commit it, and put it back with `git checkout -- dist && git clean -fdq dist`), `npm run test` (Vitest), `npm run typecheck`, `npm run lint`. Node 22. After each merge into `develop`, the Build dist workflow opens a pull request into `develop` with the build; merge it before starting a release branch.
- Docs site (ADR-0029): `npm ci --prefix docs` once, then `npm run --prefix docs build`, which fails on a link to a missing file, page or heading, or `npm run --prefix docs dev`. Write docs with links relative to their file, as GitHub reads them. Keep the README's `<!-- #region … -->` comments: the site includes those sections.
- `scripts/verify-install.sh 12|13`: installs the package into a fresh Laravel app and boots it

## Workflow (IMPORTANT, see ADR-0003 and ADR-0027)
- Features: spec → plan → failing tests → implementation → review. Do not skip the failing-tests step.
- While implementing, never edit files under tests/. If a test looks wrong, stop and explain why.
- Before saying a task is done, run `composer test` and `composer analyse`, and show the output.
- One-sentence changes may skip spec and plan. Bug fixes always start with a failing regression test.
- If a spec conflicts with an ADR, stop and ask. Do not silently pick one.
- A spec's open questions: record your recommendation as the decision and carry on; the maintainer vetoes in the PR. Ask first only when a question conflicts with an ADR, fixes a public API that would be hard to change after a release, or has no clearly best option.
- Branches (ADR-0032): start every branch from `develop` and open its PR against `develop`, which collects the work. Name it after its type of change, as its commits are: `feat/<name>`, `fix/<name>`, `docs/<name>`, `ci/<name>`, `chore/<name>`, `refactor/<name>` or `test/<name>`. `main` holds only the last release, its README, docs and changelog, and takes only `release/<version>` branches: CI fails any other PR into it. How a release works: CONTRIBUTING.md, "Branches and releases". There are no hotfix branches.
- Small PRs (ADR-0027): a spec ships as stacked PRs of about 300–600 `src` lines each plus their tests, each green on its own and naming the ACs it covers. The first carries the spec and lists its decisions; the last closes it. Open each once it is green, on `feat/spec-NNN-M` branched from the one below, and always against `develop`: its diff then shows the PRs below it too, until they merge (`develop` merges with merge commits, so no rebase). Merge review fixes up the stack; never rebase or force-push a branch with an open PR without asking.
- Right-size the checks: `composer test:mutate` when src/Engine changes, `composer test:drivers` when the stores or their use change, benchmarks when the request path's cost can move, and the review subagent for src/Engine, security-relevant code and the public API.
- Pace: stop for the maintainer's review at the end of each roadmap phase.
- Commit failing tests (`test: …`) before the implementation, and check they fail for the right reason.
- A Stop hook (`.claude/hooks/verify.sh`) runs `composer test` and `composer analyse` when code changed, and blocks the turn while they fail. At the Red step (only tests/ changed since the last green run) it warns instead of blocking.

## Hard rules
- No database queries on the request path (src/Engine, src/Http/Middleware). The database is only for admin actions and the compiler.
- Engine code never calls time() or microtime() directly: the cache driver uses the Clock, the Redis driver uses TIME inside Lua.
- All cache and Redis keys come from KeyBuilder.
- No exception from storage may escape the middleware (ADR-0010).
- Dashboard routes are never subject to dynamic rules.
- Dashboard: React + shadcn/ui in `resources/dashboard/`, built by Vite into the committed `dist/`, which users get with nothing to install (ADR-0028). No inline scripts or styles; runtime style elements carry the page's nonce. Never ship sources or `node_modules` in the Composer download, and never edit `dist/` by hand or commit it in a pull request: the Build dist workflow commits the build after merge (ADR-0030).
- tests/Arch enforces several of these rules. Never weaken an arch test to make code pass.

## Style
- PHP 8.3, `declare(strict_types=1)`, final classes by default, readonly value objects, backed enums instead of string constants.
- Pest tests. Test names start with the acceptance-criterion id when one exists: `it('AC-3: …')`.
- Conventional commits (`feat:`, `fix:`, `test:`, `docs(spec):`, `refactor:`, `ci:`, `chore:`).
- Public files are for users only (ADR-0033): `README.md`, `docs/guide/`, `docs/runbook.md`, `docs/site/` and `CHANGELOG.md` never mention or link ADRs, specs, the roadmap, tests, CI, benchmark commands, branches or PRs. State a decision's effect in plain words instead. That content goes in CONTRIBUTING.md, CLAUDE.md, `docs/adr/`, `docs/specs/` and `docs/roadmap.md`.
- `CHANGELOG.md` lists only what users notice, under **Added** and **Fixed**. A change an app must act on goes in the release's first paragraph, as its upgrade note. Dependency updates, CI, tests, the docs site and repository changes get no entry.
