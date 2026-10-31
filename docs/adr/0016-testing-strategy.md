# ADR-0016: Testing strategy

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0 to 6
- Related: ADR-0003, ADR-0005, ADR-0006
- Amended by: [ADR-0018](0018-minimal-dashboard.md) (Vitest and Playwright replaced by Pest feature tests)
- Amended: 2026-09-25, mutation testing waits for a PR to leave draft and runs in parallel parts (see the decision record)
- Amended: 2026-10-08, a PR mutates only the engine files it changes, and a weekly run mutates the whole engine (see the decision record)
- Amended: 2026-10-31 by [ADR-0032](0032-develop-and-release-branches.md): a release's pull request into `main` runs the full matrix and mutation testing of the whole engine, and pushes to `develop` run the full matrix as pushes to `main` do.

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

- `pest --mutate` on `src/Engine`, minimum score 85%. Each CI run must reach 85% on its own.
- A PR mutates what it can move the score of, once it is ready for review. `scripts/mutation-paths.sh` decides:
  - the `src/Engine` files it changes, and the engine classes its changed tests name in `covers()`, with the driver tests and their services;
  - the whole engine, when it changes what every test runs on: `composer.json`, `phpunit.xml.dist`, `tests/Pest.php`, `tests/TestCase.php` or CI;
  - nothing, when it changes none of these. A change to `tests/Support` alone is left to the next run of the whole engine.
  - A draft PR skips it. Marking the PR ready runs it.
- The whole engine is mutated nightly while the repository is public, weekly while it is private, and on manual runs, in parallel parts.

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

## Decision record

Amended on 2026-09-25, after a mutation run took about 8.5 of a pull request's CI minutes on every push. CI had run it on every pull request, not only those that touch the engine. The maintainer accepted the recommendation:
- Mutation testing runs only on pull requests that touch the engine, as decided above.
- It runs in three parallel parts: the Redis store, the cache store, and the rest of the engine. The Redis store part takes the longest, and the rest needs no service containers.
- A draft pull request skips it, and marking the pull request ready runs it.
- Mutating only the files a pull request changes was rejected for now. The nightly run is off while the repository is private, so nothing would check the whole engine's score. Revisit it when the nightly run is back.

Amended on 2026-10-08, after mutation testing took 18 minutes of a pull request's 19-minute CI run, and 37 of its 50 billed minutes, however little of the engine the pull request changed. PR #22 changed one engine file, the Sampler, and each of its pushes mutated the whole engine. The maintainer accepted the recommendation:
- A pull request mutates only the engine files it changes and the engine classes its changed tests cover. This reverses the rejection above: a weekly run of the whole engine, while the repository is private, answers the reason for it.
- Measured with CI's flags on a 4-CPU machine: the Sampler alone took 38 seconds, PR #24's nine engine files 213 seconds, and the whole engine without the stores 438 seconds, which took 1,086 seconds on CI's 2-CPU runner. On CI, a one-file change takes about 2 minutes instead of 19.
- The run of the changed files includes the driver tests and their services. Without them, PR #24's nine files scored 79%, because the Redis telemetry buffer's own tests are driver tests. The whole engine's 92% hides that.
- The trade-off: a change that weakens the tests of a file it does not touch, such as a change to `tests/Support`, is caught by the next run of the whole engine, within a week, not by its pull request.
