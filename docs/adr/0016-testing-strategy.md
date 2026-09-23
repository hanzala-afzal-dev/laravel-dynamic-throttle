# ADR-0016: Testing strategy

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0 to 6
- Related: ADR-0003, ADR-0005, ADR-0006
- Amended by: [ADR-0018](0018-minimal-dashboard.md) (the Frontend test layer)

## Context

Tests are the verification loop for Claude Code (ADR-0003), and the only reliable way to catch quiet rate-limiting bugs: off-by-one limits, races, clock edges.

## Decision

### Tools

- Pest + Orchestra Testbench (with the Workbench app for manual testing).
- Larastan (at least level 8), Pint, Rector.
- Vitest for the dashboard, plus one end-to-end smoke test (Playwright or Nightwatch).
- PHPBench for benchmarks.

### Test layers

| Layer | What | Example |
|---|---|---|
| Unit | Pure logic, table-driven | SPEC-001 formula and `Retry-After` |
| Driver contract | **One shared suite run against every store** | Same sequence → same decisions on Redis, Valkey, Memcached, database, array |
| Model-based | Random sequences + fake clock, compared with the PHP reference | Lua driver matches the reference; no aligned window exceeds `max` |
| Concurrency | Many parallel processes hit one counter | 50 workers × 20 requests at limit 100 |
| Feature | Middleware on real routes through Testbench | Stacking, scopes, headers, dry-run, failure policy |
| Security | Authorization matrix, audit | Guest/viewer/manager per endpoint |
| Frontend | Component logic + one end-to-end flow | Create rule → hit endpoint → see block |
| Benchmark | Middleware overhead | 100 active rules, 3 matched, warm manifest |

### Concurrency acceptance

- **Redis driver:** allowed count equals `max` exactly.
- **Cache driver** (Memcached/Redis stores): allowed count is never above `max`, and at least `max − workers`, because compensation can cause rare early rejections.

### Time

- All time-dependent tests use a fake clock: Laravel's `travel()` for the cache driver, the injected time argument for Lua.
- No `sleep()` in tests.

### Mutation testing

- `pest --mutate` on `src/Engine`, minimum score 85%.
- Run on PRs that touch the engine, and nightly.

### CI

- PHP 8.3/8.4/8.5 × Laravel 12/13 × lowest/stable dependencies.
- Service containers: Redis, Valkey, Memcached, MySQL, PostgreSQL.
- On PRs: minimum and maximum versions plus all drivers. Nightly: the full matrix.

### Coverage

About 90% line coverage on `src/Engine` and `src/Rules`. No 100% target elsewhere.

### Performance claims

Performance numbers are published only from the PHPBench suite, with the hardware stated.

## Reasoning

- The shared contract suite is what makes "same behavior on every driver" (ADR-0005) a checked fact instead of a hope.
- Model-based and concurrency tests find bugs that example tests miss.
- Mutation testing guards against a known weakness of AI-written tests: tests that pass no matter what the code does.

## Alternatives considered

- **PHPUnit instead of Pest.** Works, but Pest is the Laravel-ecosystem default and has built-in mutation testing. Rejected.
- **Mocking the stores.** Hides real atomicity behavior. Rejected for driver tests; allowed only for failure-injection tests.

## Fallback

- If the full matrix is too slow or flaky, run minimum/maximum versions on PRs and the full matrix nightly (already the plan).
- If concurrency tests are flaky on CI runners, run them with more iterations nightly and fewer on PRs, and never delete them.
- If the mutation threshold blocks progress early on, start at 70% and raise it by 5 points per phase.
- **Revisit when:** the average CI time on PRs exceeds 15 minutes.

## Consequences

- Good: high confidence in the parts that matter most.
- Bad: a complex CI setup with service containers. It needs a local `docker compose` file too.

## Verification

- CI is green.
- The mutation score and coverage are reported on every engine PR.
- The benchmark results file is committed per release.
