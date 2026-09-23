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
- Tests must be safe under `--parallel`, which mutation testing uses. Never write into Testbench's shared skeleton, and never rely on server-wide Redis stats. Tag slow multi-process tests `->group('concurrency')`.
- `composer test:drivers`: the driver tests plus the LimiterStore contract suite against every docker compose service
- No PHP 8.3+ on the host (the maintainer's machine has 8.2): prefix with `docker compose run --rm php`, e.g. `docker compose run --rm php composer test`
- `docker compose up -d`: Redis, Valkey, Memcached, MySQL, PostgreSQL for driver tests (`composer test:drivers`)
- `scripts/verify-install.sh 12|13`: installs the package into a fresh Laravel app and boots it

## Workflow (IMPORTANT, see ADR-0003)
- Features: spec → plan → failing tests → implementation → review. Do not skip the failing-tests step.
- While implementing, never edit files under tests/. If a test looks wrong, stop and explain why.
- Before saying a task is done, run `composer test` and `composer analyse`, and show the output.
- One-sentence changes may skip spec and plan. Bug fixes always start with a failing regression test.
- If a spec conflicts with an ADR, stop and ask. Do not silently pick one.
- Pace: stop for the maintainer's review at the end of each roadmap phase.
- Commit failing tests (`test: …`) before the implementation, and check they fail for the right reason.
- A Stop hook (`.claude/hooks/verify.sh`) runs `composer test` and `composer analyse` when code changed, and blocks the turn while they fail. At the Red step (only tests/ changed since the last green run) it warns instead of blocking.

## Hard rules
- No database queries on the request path (src/Engine, src/Http/Middleware). The database is only for admin actions and the compiler.
- Engine code never calls time() or microtime() directly: the cache driver uses the Clock, the Redis driver uses TIME inside Lua.
- All cache and Redis keys come from KeyBuilder.
- No exception from storage may escape the middleware (ADR-0010).
- Dashboard routes are never subject to dynamic rules.
- Dashboard: Blade + one hand-written JS file. No npm, no package.json, no inline scripts or styles (ADR-0018).
- tests/Arch enforces several of these rules. Never weaken an arch test to make code pass.

## Style
- PHP 8.3, `declare(strict_types=1)`, final classes by default, readonly value objects, backed enums instead of string constants.
- Pest tests. Test names start with the acceptance-criterion id when one exists: `it('AC-3: …')`.
- Conventional commits (`feat:`, `fix:`, `test:`, `docs(spec):`, `refactor:`, `ci:`, `chore:`).
