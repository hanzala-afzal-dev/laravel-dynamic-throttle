# ADR-0002: Supported PHP, Laravel and backends

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 0
- Amended by: [ADR-0019](0019-tested-laravel-floors.md) (the Laravel constraint is `^12.61.1 || ^13.12.0`, the versions CI tests)

## Context

The first design draft targeted PHP 8.2+ and Laravel 10–12. The support situation in September 2026:

- Laravel 10 and 11 no longer receive security fixes.
- Laravel 12 receives security fixes until 24 February 2027.
- Laravel 13 was released on 17 March 2026 and requires PHP 8.3. Its security fixes run until Q1 2028.
- PHP 8.2 security support ends at the end of 2026, so it will likely be end-of-life before this package reaches 1.0.

## Decision

- **PHP:** `^8.3` (tested on 8.3, 8.4, 8.5).
- **Laravel:** `^12.0 || ^13.0`. Add the next Laravel major within about four weeks of its release.
- **Counter and manifest storage:**
  - Redis 6.2+ and Valkey 7.2+ through Laravel's Redis layer (phpredis or predis).
  - Memcached 1.6+ and any other Laravel cache store through the generic cache driver (ADR-0005).
- **Database for rules and audit log:** whatever Laravel 12/13 supports. CI tests MySQL 8, PostgreSQL 16 and SQLite.
- **Runtimes:** PHP-FPM and Laravel Octane (Swoole, RoadRunner, FrankenPHP).
- **Support policy:** framework or PHP versions are dropped only in a new major version (or a minor version while still in 0.x), and only after they reach end of life.

## Reasoning

- Supporting end-of-life versions costs CI time and compatibility code for users who should upgrade anyway.
- Laravel 12 stays in because it is still security-supported and many production apps sit there.
- PHP 8.3 is required by Laravel 13 anyway, and gives typed class constants and `json_validate()`.
- Octane matters because its long-lived workers make the in-memory rule cache (ADR-0007) much more effective.

## Alternatives considered

- **Keep Laravel 11 / PHP 8.2.** Both are end-of-life before our 1.0. Rejected.
- **Laravel 13 only.** Excludes a large share of production apps. Rejected.

## Fallback

- If supporting Laravel 12 forces awkward code, drop it in the first major release after its security end (February 2027).
- If users ask for Laravel 11 / PHP 8.2 support, point to the upgrade guides; do not backport.
- **Revisit when:** each new Laravel major release, and every February when Laravel 12's status changes.

## Consequences

- Good: a modern codebase (readonly classes, backed enums, typed constants) and a manageable CI matrix.
- Bad: users on older stacks cannot adopt the package.

## Verification

- CI matrix: PHP 8.3/8.4/8.5 × Laravel 12/13 × `prefer-lowest`/`prefer-stable`.
- The package installs and boots in fresh Laravel 12 and Laravel 13 apps (Phase 0 exit criterion).
