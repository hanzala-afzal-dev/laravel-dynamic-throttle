# Roadmap

## Principles

- **Risk first.** The engine (correctness, concurrency, failure handling) is built and proven before any UI. UI bugs are visible; engine bugs are silent.
- **Always releasable.** Each phase ends in a state that could ship. The first public release (0.1) happens before the dashboard exists.
- **Exit criteria are commands, not feelings.** A phase is done when its checks pass in CI.
- **Every feature goes through the ADR-0003 loop:** spec → plan → failing tests → code → review.

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

**Exit criteria**
- Every acceptance criterion has a passing test.
- Zero database queries during a throttled request with a warm manifest (query-count assertion).
- Benchmark: added p95 latency below 1 ms with 100 active rules and 3 matched, on local Redis. This target is a hypothesis; adjust it with data and record the change in the spec.

**Fallback:**
- Matching is slow → tighten the pre-index, or add route-name matching.
- Manifest reads are slow on FPM → make APCu the documented recommendation.

---

## Phase 3 — Operability (M) → release 0.1.0

**Goal:** the package is safe to run in production without a UI.

**Specs**
- SPEC-013: emergency controls + CLI (`status`, `bypass`, `scale`, `list`, `compile`) (ADR-0011)
- SPEC-014: `dynamic-throttle:explain` (simulate a request and show matched rules, identity and decision)
- SPEC-015: telemetry buffer, flush, prune, events (ADR-0013)
- SPEC-016: health checks (store type warnings, middleware order, scheduler, dominant IP)

**Exit criteria**
- `docs/runbook.md` covers "Redis down", "manifest evicted", "a bad rule blocks /api" and "forgot to undo an emergency change". Each scenario has a test.
- 0.1.0 is tagged and on Packagist (CLI-only), and the README states the pitch and non-goals (ADR-0001).

**Fallback:** if telemetry overhead is too high, lower the default sampling rate before release.

**Why release here:** real feedback on the engine arrives weeks earlier, while the UI is being built.

---

## Phase 4 — Dashboard API and security (M)

**Specs**
- SPEC-017: JSON API (rules, exemptions, settings, metrics, audit, health, explain)
- SPEC-018: gates, middleware, validation, guardrails (ADR-0015)
- SPEC-019: audit log

**Exit criteria**
- An authorization test for every endpoint × role.
- Every mutation writes exactly one audit entry.
- The dashboard stays reachable under a `*` rule.
- A security review is done (subagent + manual checklist).

**Fallback:** if gates are too coarse for early users, add a policy-class override in config.

---

## Phase 5 — Dashboard UI (M) → release 0.2.0

Minimal dashboard: server-rendered Blade plus one hand-written JavaScript file, no build step (ADR-0018, which supersedes ADR-0014).

**Specs**
- SPEC-020: Blade layout, asset route, security headers and CSP (ADR-0018)
- SPEC-021: Overview + emergency controls
- SPEC-022: Rules + dry-run promote
- SPEC-023: Exemptions
- SPEC-024: Activity + offenders
- SPEC-025: Audit log
- SPEC-026: Explain tool

**Exit criteria**
- Every page has a feature test, including the CSP header and no-inline-code checks (ADR-0018).
- The end-to-end smoke test passes: create a rule → send requests → see the block in Activity.
- CI confirms the repository has no `package.json`.
- Screenshots are in the README.
- The dashboard works under the strict CSP in the Workbench app.

**Fallback:** if the phase runs more than 50% over its estimate, ship 0.2.0 with the API and the finished pages, and move the rest to a later minor release.

---

## Phase 6 — Hardening and 1.0 (M + 4 weeks dogfooding)

**Deliverables**
- Full docs: install, scopes, per-driver guarantees table, runbook, comparison page, upgrade guide, `SECURITY.md`.
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

## Decisions made in Phase 0 (2026-09-23)

These were the open decisions of the original roadmap.

- **Package name:** `hanzala-afzal/laravel-dynamic-throttle` on Packagist. PHP namespace `Afzal\DynamicThrottle`.
- **Repository:** `github.com/hanzala-afzal-dev/laravel-dynamic-throttle`.
- **License:** MIT, copyright Hanzala Afzal. Contributions are accepted under the same license (inbound = outbound, see `CONTRIBUTING.md`).
- **Docs hosting:** README + `docs/` folder until 1.0.
- **PHP floor:** PHP 8.2 support was requested and declined. ADR-0002 stands (`^8.3`).
- **Laravel floor:** `^12.61.1 || ^13.12.0`, the lowest versions CI tests (ADR-0019, amends ADR-0002).
- **Repository:** private until 0.1.0. Nightly CI is skipped while private, to save Actions minutes. GitHub offers these three settings only on public repositories, or with GitHub Pro. Turn them on when the repository goes public:
  - private vulnerability reporting (`SECURITY.md` links to it)
  - branch protection on `main` requiring the `CI passed` check
  - reported content to repository admins (`CODE_OF_CONDUCT.md` relies on it)
- **Dashboard:** minimal Blade + vanilla JavaScript (ADR-0018).
