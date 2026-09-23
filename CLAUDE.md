# laravel-dynamic-throttle

Laravel package: runtime-managed, identity-aware rate limiting with dry-run and a dashboard.
Decisions live in docs/adr/ (index: docs/adr/README.md). Feature specs live in docs/specs/. The roadmap is docs/roadmap.md.

## Commands (created in Phase 0)
- `composer test`: Pest. Single test: `composer test -- --filter=Name`
- `composer analyse`: Larastan
- `composer format`: Pint
- `docker compose up -d`: Redis, Valkey, Memcached, MySQL, PostgreSQL for driver tests
- `cd dashboard && npm test` / `npm run build`: dashboard (Phase 5)

## Workflow (IMPORTANT, see ADR-0003)
- Features: spec → plan → failing tests → implementation → review. Do not skip the failing-tests step.
- While implementing, never edit files under tests/. If a test looks wrong, stop and explain why.
- Before saying a task is done, run `composer test` and `composer analyse`, and show the output.
- One-sentence changes may skip spec and plan. Bug fixes always start with a failing regression test.
- If a spec conflicts with an ADR, stop and ask. Do not silently pick one.

## Hard rules
- No database queries on the request path (src/Engine, src/Http/Middleware). The database is only for admin actions and the compiler.
- Engine code never calls time() or microtime() directly: the cache driver uses the Clock, the Redis driver uses TIME inside Lua.
- All cache and Redis keys come from KeyBuilder.
- No exception from storage may escape the middleware (ADR-0010).
- Dashboard routes are never subject to dynamic rules.

## Style
- PHP 8.3, `declare(strict_types=1)`, final classes by default, readonly value objects, backed enums instead of string constants.
- Pest tests. Test names start with the acceptance-criterion id when one exists: `it('AC-3: …')`.
- Conventional commits (`feat:`, `fix:`, `test:`, `docs(spec):`, `refactor:`).
