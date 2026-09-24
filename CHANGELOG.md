# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html). Before 1.0, minor versions may contain breaking changes; they are called out under **Changed** with an upgrade note.

## [Unreleased]

### Added

- Package skeleton for Laravel 12.61.1+ and 13.12+ on PHP 8.3+:
  - an auto-discovered service provider
  - a publishable config file (`dynamic-throttle-config` tag)
  - the `DYNAMIC_THROTTLE_ENABLED` kill switch
  - a "Dynamic Throttle" section in `php artisan about`
- The counter engine, with no rules or HTTP yet:
  - a sliding-window counter, with an exact `Retry-After`
  - the `LimiterStore` contract: `attempt()` counts a request, `release()` uncounts it when a later rule rejects it
  - a Redis and Valkey driver (one Lua script per call, time from Redis `TIME`) and a driver for any Laravel cache store
  - config keys `driver` (`auto`, `redis`, `cache`), `store`, `redis_connection` and `prefix`
  - `StorageUnavailableException` for every storage failure
- Config key `database.connection` (`DYNAMIC_THROTTLE_DB_CONNECTION`): the connection for the package's tables.
- A published config keeps working across upgrades: new nested defaults are added, and lists stay as you wrote them.
- The rules table, `dynamic_throttle_rules`: publish it with `php artisan vendor:publish --tag=dynamic-throttle-migrations`, then run `php artisan migrate`.
- The `Rule` model, and the backed enums `RequestMethod`, `Scope`, `RuleMode`, `FailurePolicy` and `MissingIdentity`.
- The rules manifest: requests read the rules from the cache, never from the database.
  - Saving a rule recompiles it after the transaction commits.
  - `php artisan dynamic-throttle:compile` compiles it from deploy scripts, and the scheduler runs it every five minutes, so a cleared cache heals itself (`manifest.schedule`).
  - Octane and queue workers keep it in memory, and check the cache every `manifest.refresh_seconds` (`DYNAMIC_THROTTLE_MANIFEST_REFRESH`, default 2).
- Rule matching and stacking, not yet applied to requests:
  - A rule matches by method and by a pattern on the URL-decoded path. `*` matches every method, and `GET` rules also match `HEAD`. In patterns, `*` matches any characters, and a leading slash is ignored.
  - Every matching rule counts the request, in priority order. The first one that rejects it stops, and the others' counts are released. Dry-run rules never reject.
  - Config key `matching.max_rules_per_request` (`DYNAMIC_THROTTLE_MAX_RULES`, default 5): at most this many enforcing rules count a request, and as many dry-run rules. Rules that do not apply to the request take no slot, and adding a dry-run rule never switches off an enforcing one. Rules beyond the cap are ignored.
- Scopes decide who a rule counts a request for. Like matching, they are not yet applied to requests:
  - `ip`, `user`, `api_token`, `header` and `global`, plus custom scopes that you register with `DynamicThrottle::scope('tenant', fn (Request $request) => ...)` or a `ScopeResolver` class. Per-tenant limits are one rule with a custom scope.
  - IPv6 clients are grouped by `scopes.ip.ipv6_prefix` (`DYNAMIC_THROTTLE_IPV6_PREFIX`, default 64), so rotating addresses inside a /64 does not escape a limit.
  - By default, the `user` scope counts the user the request authenticated as, without a lookup of its own. `scopes.user.guards` lists guards to ask instead.
  - A request without an identity counts under its IP, or skips the rule, as the rule's `on_missing_identity` says.
  - Identities are hashed with a key derived from `APP_KEY`, so no raw IP, token or user id reaches a cache key.
