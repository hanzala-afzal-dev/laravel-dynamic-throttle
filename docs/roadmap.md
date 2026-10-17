# Roadmap

## Principles

- **Risk first.** The engine (correctness, concurrency, failure handling) is built and proven before any UI. UI bugs are visible; engine bugs are silent.
- **Always releasable.** Each phase ends in a state that could ship. The first public release, 0.1.0, waits for the dashboard: the package ships with its dashboard, as Laravel Horizon does (decided on 2026-10-08).
- **Exit criteria are commands, not feelings.** A phase is done when its checks pass in CI.
- **Every feature goes through the ADR-0003 loop:** spec → plan → failing tests → code → review. Since the Phase 2 exit, ADR-0027 shortens the waits in it.

Sizes are rough: **S** (a few days), **M** (1–2 weeks), **L** (2–3 weeks) of focused work.

---

## Phase 0 — Foundation (S)

**Goal:** a repository where Claude Code can verify its own work from day one.

**Why first:** without working `test`/`analyse`/`format` commands, Claude has no pass/fail signal, and every later phase depends on that loop.

**Deliverables**
- Package skeleton: composer.json, service provider, config, Testbench + Workbench app.
- Composer scripts: `test`, `analyse` (Larastan), `format` (Pint), `refactor` (Rector).
- `docker-compose.yml` with Redis, Valkey, Memcached, MySQL, PostgreSQL, plus a PHP 8.3 dev container so the suite runs without a local PHP 8.3.
- GitHub Actions matrix (ADR-0016), with a placeholder smoke test.
- `CLAUDE.md`, the ADRs, `docs/specs/TEMPLATE.md`.
- Claude Code Stop hook that runs tests and analysis (ADR-0003).
- Open-source housekeeping: `LICENSE`, `README.md`, `SECURITY.md`, `CONTRIBUTING.md`, `CHANGELOG.md`, PR template, Dependabot.

**Exit criteria**
- CI is green across the matrix.
- The package installs through a path repository into fresh Laravel 12 and 13 apps, and boots (`scripts/verify-install.sh`, also a CI job).
- `composer test`, `composer analyse` and `composer format` run locally (natively on PHP 8.3+, or through the dev container).

**Fallback:** if the matrix is slow or flaky, run minimum/maximum versions on PRs and the full matrix nightly.

---

## Phase 1 — Core engine (L)

**Goal:** correct, fast counters on every supported store. No rules, no HTTP yet.

**Why now:** this is the highest-risk, most-reused code. Everything else calls it.

**Specs**
- SPEC-001: sliding-window counter formula (pure PHP)
- SPEC-002: `LimiterStore` contract + generic `cache` driver
- SPEC-003: `redis` driver (Lua, `TIME`, `EVALSHA` fallback)
- SPEC-004: compensation (`release`)

**Exit criteria**
- The shared contract suite passes on Redis (phpredis + predis), Valkey, Memcached, the database store and the array store.
- The concurrency test meets ADR-0016's acceptance rules.
- The model-based test shows the Lua driver matching the PHP reference.
- A benchmark baseline is recorded.

**Fallback:** if the sliding counter or the Lua port is not solid by the end of the phase, ship fixed window (Laravel-compatible) behind the same interface and finish the sliding counter in 0.2 (ADR-0006).

---

## Phase 2 — Rules and middleware (L)

**Goal:** rules from the database apply to real requests, without touching routes.

**Specs**
- SPEC-005: schema, models, backed enums
- SPEC-006: manifest compiler, versioning, layered read path (ADR-0007)
- SPEC-007: matcher, pre-index, stacking (ADR-0008)
- SPEC-008: scopes + custom resolvers (ADR-0009)
- SPEC-009: exemptions
- SPEC-010: middleware, priority, headers, 429 (ADR-0004)
- SPEC-011: dry-run (ADR-0012)
- SPEC-012: failure policy (ADR-0010)

**Added by ADR-0020 and ADR-0021 (2026-09-24)**
- First, before any nested config key ships: the config merge that keeps new defaults and replaces lists (ADR-0020 point 10).
- SPEC-005: `dynamic_throttle_*` tables, a configurable connection, published migrations (point 7).
- SPEC-007: equal priorities run in id order (point 6).
- SPEC-008: `scopes.user.guards` (point 5), and a `custom:tenant` example for per-tenant limits (point 8).
- SPEC-010:
  - the `except` list and `middleware.after` (points 3 and 4)
  - nothing attached by default (point 11)
  - releasing dry-run rules too (ADR-0021)
  - `DynamicThrottle::fake()` for host apps' tests (point 12)
- SPEC-012:
  - every exception caught, with `report()` and strict mode (point 1)
  - a failed release keeps the rejection and starts the cooldown (ADR-0021)
- SPEC-016 (Phase 3): the shared-database warning, missing `except` paths, two limiters on one route, and config checks (points 1, 2, 3 and 9).

**Added by the SPEC-007 review (2026-09-24)**
- Right after SPEC-008, in its own PR: ADR-0025, amending ADR-0008 so the cap counts only rules that count the request. Skipped rules never use a slot, and dry-run rules get slots of their own, so adding a dry-run rule never switches off an enforcing one.
- A `perf:` PR that makes decoding the manifest cheaper without changing what it accepts, measured with `RuleMatcherBench` (`benchmarks/results/phase-2-manifest-decoding.md`). The APCu cache (ADR-0007's L1) is decided with the exit benchmark.
- SPEC-018 (Phase 4): saving a rule whose pattern ends with a slash is rejected, because it can never match.

**Exit criteria**
- Every acceptance criterion has a passing test.
- Zero database queries during a throttled request with a warm manifest (query-count assertion).
- Benchmark: added p95 latency below 1 ms with 100 active rules and 3 matched, on local Redis. This target is a hypothesis; adjust it with data and record the change in the spec.

**Fallback:**
- Matching is slow → tighten the pre-index, or add route-name matching.
- Manifest reads are slow on FPM → make APCu the documented recommendation.

**Exit review (2026-09-28)**
- Every spec is merged, and the first two exit criteria hold.
- The benchmark missed the 1 ms target on local Redis: 1.25 ms at p95 with the manifest in memory, and 2.3 ms with the manifest read per request (`benchmarks/results/phase-2-exit.md`). Most of it is one Redis round trip per matched rule, and, on PHP-FPM, reading the manifest on every request.
- The maintainer accepted four decisions, now in Phase 3:
  1. one store round trip per request (ADR-0026, SPEC-027);
  2. the manifest's APCu cache on PHP-FPM (ADR-0007's L1, SPEC-028);
  3. explicit container bindings for the request path, in a `perf:` PR;
  4. a restated target, which mostly measured the network: one store round trip per request, and under 0.5 ms of the package's own time at p95. It is a Phase 3 exit criterion.
- The maintainer also accepted a faster loop: ADR-0027.

---

## Phase 3 — Operability (M)

**Goal:** the package can be run and operated safely from code and Artisan alone, before its dashboard exists.

**First, from the Phase 2 exit review**
- A `perf:` PR: explicit container bindings for the request path's services, measured with `MiddlewareBench`.
- SPEC-027: one store round trip per request (ADR-0026)
- SPEC-028: the manifest's APCu cache on PHP-FPM (ADR-0007's L1)

**Specs**
- SPEC-013: emergency controls + CLI (`status`, `bypass`, `scale`, `list`, `disable-rule`, `enable-rule`, `compile`) (ADR-0011), and the audit log's table with the CLI's entries (decided on 2026-10-01, ADR-0015's decision record)
- SPEC-014: `dynamic-throttle:explain` (simulate a request and show matched rules, identity and decision)
- SPEC-015: telemetry buffer, flush, prune, events (ADR-0013)
- SPEC-016: health checks (store type warnings, middleware order, scheduler, dominant IP)
- SPEC-029: the runbook, `docs/runbook.md`, with a test per scenario, and the `emergency` health check for settings left on

**Before the first release, 0.1.0, from the SPEC-013 review (2026-10-01)**, decided at the Phase 3 review (2026-10-13):
- **Yes, first in Phase 4:** the manifest's cache key names its format, such as `dt:m:3`, so servers of two versions never rebuild each other's manifest during a rolling deploy.
- **Yes, first in Phase 4:** the package's tables store times in UTC, the exemptions' and the settings' end times and the audit log's, so a daylight-saving change cannot stretch an end time by the repeated hour.
- **Kept at 1:** telemetry's default `sample_rate`, the fallback below (SPEC-015's benchmark, `benchmarks/results/phase-3-telemetry.md`): at 1, telemetry costs about 5 µs on the response path, and after it one round trip on Redis, or about 1 ms of worker time on Memcached, where the cache driver makes two round trips per rule and metric. The README tells busy apps on Memcached to lower it.
- **After 0.1.0:** the `dominant-ip` check behind several proxies (SPEC-016's review), which needs real traffic to tune: behind a load balancer with several nodes, such as one per availability zone, each node's IP sends a share of the traffic and none may reach half, so the check says ok while `ip` rules count each node as one client. Either also warn when a few clients, say five, send most of at least 1,000 requests, which also warns about a few known heavy clients, or count each hour's requests from private and reserved ranges, a sign of an untrusted proxy that stores no client IP.

**Exit criteria**
- Benchmark, with 100 active rules and 3 matched, restated at the Phase 2 exit:
  - the Redis driver makes one round trip per request, outside Redis Cluster, which a test counts;
  - the package's own time, measured on the array store, is under 0.5 ms at p95, on Octane and on PHP-FPM with APCu.
- `docs/runbook.md` covers "Redis down", "manifest evicted", "a bad rule blocks /api" and "forgot to undo an emergency change". Each scenario has a test.
- The README states the pitch and the non-goals on its first screen (ADR-0001).

**Fallback:** if telemetry overhead is too high, lower the default sampling rate before release.

**No release here.** On 2026-10-08 the maintainer decided that the package is released only with its dashboard, so the first release, 0.1.0, moves to the end of Phase 5 (ADR-0018's decision record). Phase 3 still ends with the maintainer's review.

**Done.** The maintainer reviewed Phase 3 on 2026-10-13: every exit criterion holds, with 415 µs at p95 on PHP-FPM with APCu and 302 µs on Octane, and the decisions above are taken.

---

## Phase 4 — Dashboard API and security (M)

**First, from the Phase 3 review**
- The manifest's cache key names its format (SPEC-006).
- The package's tables store times in UTC (SPEC-009, SPEC-013).

**Specs**
- SPEC-017: JSON API (rules, exemptions, settings, metrics, health, explain), [the spec](specs/017-json-api.md): each change writes its audit entry in the same transaction, and explain is a POST that masks bearer tokens and header values (decided on 2026-10-14).
- SPEC-018: gates, middleware, validation, guardrails (ADR-0015), [the spec](specs/018-dashboard-access-and-guardrails.md): the dashboard's route group behind `['web']` and the gates, its own Laravel limit, its path never limited, validation that a saved rule always compiles, and `confirm` for dangerous changes
- SPEC-019: audit log, [the spec](specs/019-audit-log.md): reading it (`GET api/audit`), its filters and its pages, and a test that every change writes exactly one entry. The table and the CLI's entries ship with SPEC-013, and the API's entries with SPEC-017.

**Exit criteria**
- An authorization test for every endpoint × role.
- Every mutation writes exactly one audit entry.
- The dashboard stays reachable under a `*` rule, also with `dynamic.throttle` as global middleware. Global middleware runs before routing, so it cannot tell the package's routes by their names (found by SPEC-014's review).
- A security review is done (subagent + manual checklist).

**Fallback:** if gates are too coarse for early users, add a policy-class override in config.

**Exit evidence (2026-10-15), for the maintainer's review**
- Authorization for every endpoint × role: `tests/Feature/Dashboard/Api/AuthorizationMatrixTest.php` sends every route as a guest, a viewer and a manager, and fails when a route has no row (SPEC-017 AC-20, SPEC-019 AC-4).
- One audit entry per change: `OneEntryPerChangeTest.php` sends every change route, listed from the router, and finds one entry naming the change, and none for a repeated no-op (SPEC-019 AC-6); the CLI's, SPEC-013 AC-23.
- Reachable under a `*` rule: `ReachableUnderStarRuleTest.php` sends every route of the group twice under a rule of 1 a minute, with `dynamic.throttle` on the `web` group and as global middleware.
- Security review: a review subagent read SPEC-018's, SPEC-017's and SPEC-019's code; its findings were fixed test-first or written into the specs' edge cases (SPEC-018 AC-20 to AC-26, SPEC-017 AC-25 and AC-26, SPEC-019 AC-8). The manual checklist, from ADR-0015:
  - **Authorization:** two gates, checked per request; any request but GET, HEAD and OPTIONS needs `manageDynamicThrottle`, except `POST api/explain` through the `inspect` ability, which a test keeps to that one route.
  - **Validation and mass assignment:** every change goes through `RuleInput`, `ExemptionInput` or `SettingInput`, which keep only the fields they know; ids are numbers in the routes.
  - **XSS in rule names:** the API answers JSON only, with `nosniff`; names refuse control and invisible format characters. The pages (SPEC-020) must write them as text, never as HTML.
  - **CSRF:** every change runs behind the `web` group's token check, explain included.
  - **Audit integrity:** `EmergencyControls` alone writes the log, each entry in its change's transaction; the read model refuses every write.
  - **Secrets:** explain masks tokens and header values, and never takes the logged-in admin for the request it explains.

---

## Phase 5 — Dashboard UI (M) → release 0.1.0, the first release

A dashboard that ships with the package, as Laravel Horizon's does: served at its own path behind a gate, with live numbers and charts, and every rule, exemption and emergency switch managed in the browser. React with shadcn/ui and Recharts, built ahead into a committed `dist/`, so users install nothing ([ADR-0028](adr/0028-dashboard-react-shadcn.md), decided on 2026-10-15; it amends ADR-0018). Viewers see every page read-only; managers also get the buttons that change things. Light and dark themes follow the system, with a toggle; live pages poll every 5 seconds while the tab is visible; times show in the browser's time zone, with UTC on hover.

**Specs**
- SPEC-020: the frontend foundation: the toolchain in `resources/dashboard/`, the committed `dist/` and its freshness check, the page shell and asset routes, the strict CSP with a nonce, the layout (sidebar, header with the role and the theme toggle, the banner while an emergency setting is on), the API client and polling, and CI for the frontend
- SPEC-021: Overview: health, bypass, scale and rule counts; the last hour in numbers; the traffic chart over an hour, a day or a week; the top rules by blocks; the emergency controls
- SPEC-022: Rules: the list with search and filters, a rule's page (its chart, offenders, exemptions and history), the form with templates, enable and disable, promote a dry-run rule with its last 24 hours shown, duplicate and delete
- SPEC-023: Exemptions: the list with filters, add with expiry presets, delete
- SPEC-030: a live feed of the latest blocks and would-blocks, which Activity polls: the last 500, on Redis, with the client's raw IP and user id kept for an hour, never a token or a header value, visible to viewers. This amends ADR-0024 (decided at the Phase 3 review).
- SPEC-024: Activity: the live feed, and each rule's offenders this hour or the last
- SPEC-025: Audit log: the table with before and after side by side, filters, and more on demand
- SPEC-026: Explain: the form, and the decision with each rule's identity and counter
- SPEC-031: Settings: the health checks with how to fix each, the effective configuration read-only, and the CLI commands for when the dashboard is down

**Later, not in 0.1.0:** bulk actions on rules, CSV export, and importing or exporting rules as JSON.

**Exit criteria**
- Every page has component tests (Vitest), and the shell and the routes have Pest tests, including the CSP header, the nonce and no inline code (ADR-0028).
- The end-to-end smoke test passes in a real browser: create a rule → send requests → see the block in Activity.
- CI rebuilds `dist/` and finds it identical, the first page's JavaScript stays under 300 KB gzipped, and `npm audit` finds no high or critical advisory in what ships.
- Screenshots of both themes are in the README.
- The dashboard works under the strict CSP in the Workbench app.
- `0.1.0-beta.1` is tagged and on Packagist, the repository is public, and the three GitHub settings listed under "Repository" below are on.

**Release plan (decided 2026-10-17):** a public beta first, so the community can try it before the API settles:
- `0.1.0-beta.1` once Phase 5 is done, with GitHub Discussions on and a "Beta feedback" issue template.
- More betas (`0.1.0-beta.N`) with fixes and changes from feedback, each breaking change with an upgrade note in the changelog.
- `0.1.0-RC1` with fixes only, then `0.1.0` when no serious bug is reported for a week or two.
- Then Phase 6 towards 1.0.

**Fallback:** if the phase runs more than 50% over its estimate, release 0.1.0 once Overview, Rules, Exemptions, Settings and the emergency controls are finished, since those manage throttling, and move Activity, Audit log and Explain to a minor release.

---

## Phase 6 — Hardening and 1.0 (M + 4 weeks dogfooding)

**Deliverables**
- Full docs: install, scopes (with SPEC-008's recipes, such as multi-database tenancy), per-driver guarantees table, runbook, comparison page, upgrade guide, `SECURITY.md`.
- Load test in a realistic setup (k6 or similar) against the Workbench app with Redis.
- Dogfood in at least one real app for 4 weeks.

**Exit criteria:** the 1.0 criteria in ADR-0017.

**Fallback:** if dogfooding keeps slipping, publish 1.0.0-RC and gather feedback from early adopters instead.

---

## After 1.0 (backlog, not committed)

- Filament plugin (separate package) on top of the JSON API
- Laravel Pulse card
- Rules export/import (JSON) for code review and staging → production promotion
- GCRA / burst allowance as an opt-in Redis-only algorithm
- Route-name matching
- IETF `RateLimit` headers (opt-in)
- Shadow rules: test a new limit next to an enforcing one
- Scheduled settings ("double limits Friday 18:00–22:00")
- The `dominant-ip` check behind several proxies (Phase 3 review, above)

## Decisions made in Phase 0 (2026-09-23)

These were the open decisions of the original roadmap.

- **Package name:** `hanzala-afzal/laravel-dynamic-throttle` on Packagist. PHP namespace `Afzal\DynamicThrottle`.
- **Repository:** `github.com/hanzala-afzal-dev/laravel-dynamic-throttle`.
- **License:** MIT, copyright Hanzala Afzal. Contributions are accepted under the same license (inbound = outbound, see `CONTRIBUTING.md`).
- **Docs hosting:** README + `docs/` folder until 1.0.
- **PHP floor:** PHP 8.2 support was requested and declined. ADR-0002 stands (`^8.3`).
- **Laravel floor:** `^12.69.0 || ^13.30.0`, the lowest versions CI tests (ADR-0019, amends ADR-0002). Raised from `^12.61.1 || ^13.12.0` on 2026-09-30, when a security advisory kept CI from installing the older versions.
- **Repository:** private until 0.1.0, the first release, which ships with the dashboard (Phase 5). Nightly CI is skipped while private, to save Actions minutes. GitHub offers these three settings only on public repositories, or with GitHub Pro. Turn them on when the repository goes public:
  - private vulnerability reporting (`SECURITY.md` links to it)
  - branch protection on `main` requiring the `CI passed` check
  - reported content to repository admins (`CODE_OF_CONDUCT.md` relies on it)
- **Dashboard:** ships with the package, as Laravel Horizon's does, and with the first release. React with shadcn/ui, built ahead and shipped in the package, so users install nothing (ADR-0028, 2026-10-15; it amends ADR-0018).
- **What is public (2026-09-24):**
  - The GitHub repository is the development repository. Tests, docs, CI, Docker, scripts, `CLAUDE.md` and `.claude/` stay visible, as contributors need them and Claude Code on the web reads them.
  - What users install with Composer is only the package: `src/`, `config/` (later `database/`, `resources/` and `routes/`), `composer.json`, README, LICENSE, CHANGELOG and SECURITY. The `export-ignore` rules in `.gitattributes` enforce it, and a CI allowlist check keeps it that way.
