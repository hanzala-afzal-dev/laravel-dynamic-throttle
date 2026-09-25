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
- The counter engine:
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
- Rule matching and stacking:
  - A rule matches by method and by a pattern on the URL-decoded path. `*` matches every method, and `GET` rules also match `HEAD`. In patterns, `*` matches any characters, and a leading slash is ignored.
  - Every matching rule counts the request, in priority order. The first one that rejects it stops, and the others' counts are released. Dry-run rules never reject.
  - Config key `matching.max_rules_per_request` (`DYNAMIC_THROTTLE_MAX_RULES`, default 5): at most this many enforcing rules count a request, and as many dry-run rules. Rules that do not apply to the request take no slot, and adding a dry-run rule never switches off an enforcing one. Rules beyond the cap are ignored.
- Scopes decide who a rule counts a request for:
  - `ip`, `user`, `api_token`, `header` and `global`, plus custom scopes that you register with `DynamicThrottle::scope('tenant', fn (Request $request) => ...)` or a `ScopeResolver` class. Per-tenant limits are one rule with a custom scope.
  - IPv6 clients are grouped by `scopes.ip.ipv6_prefix` (`DYNAMIC_THROTTLE_IPV6_PREFIX`, default 64), so rotating addresses inside a /64 does not escape a limit. IPv4 clients seen as IPv4-mapped (`::ffff:…`) or NAT64 (`64:ff9b::…`) addresses count as their IPv4 address.
  - By default, the `user` scope counts the user the request authenticated as, without a lookup of its own. `scopes.user.guards` lists guards to ask instead.
  - A request without an identity counts under its IP, or skips the rule, as the rule's `on_missing_identity` says.
  - A scope that cannot be resolved, such as a custom resolver that throws, fails only the rules that use it. The other rules still count the request.
  - Identities are hashed with a key derived from `APP_KEY`, so no raw IP, token or user id reaches a cache key.
- Exemptions free a client from one rule, or from every rule, until an optional expiry:
  - The table `dynamic_throttle_exemptions`: publish its migration with `php artisan vendor:publish --tag=dynamic-throttle-migrations`, then run `php artisan migrate`. Until you do, the rules compile without exemptions, and the missing table is reported.
  - The `Exemption` model: `Exemption::forIp('10.0.0.0/8')`, `Exemption::forUser($user)` or `Exemption::forCustom('tenant', 'acme')`, then `->forRule($rule)`, `->until($time)` and `->because('reason')`. Deleting a rule deletes its exemptions.
  - An IP exemption names an address or a CIDR range, IPv4 or IPv6, and checks the client IP your trusted proxies give. A user exemption names the user the `user` scope finds, and a custom one a custom scope's value, exactly.
  - Saving or deleting an exemption recompiles the manifest after the transaction commits. The manifest holds user and custom values only as hashes.
  - A failed check, such as a custom resolver that throws, exempts nothing, so it can never switch a limit off.
  - `php artisan dynamic-throttle:compile` also prints the exemptions it compiled and skipped.
- The `dynamic.throttle` route middleware applies the rules to requests:
  - It is attached nowhere until you add it to your route groups, or list them in `middleware.auto_attach`.
  - It runs after Laravel's authentication middleware, and after the middleware you list in `middleware.after`, wherever a group lists it.
  - It lets through, untouched, the paths in `except` (by default Horizon, Telescope, Pulse, Nova, Livewire, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`), the package's own routes, and every request while `enabled` is off.
  - A limited request gets Laravel's 429 through your exception handler, or the rule's `response_override` as its JSON body, with `Retry-After`, `X-RateLimit-Reset`, `X-RateLimit-Limit` and `X-RateLimit-Remaining`. An allowed request gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from its most restrictive enforcing rule. Dry-run rules set no headers.
  - When something fails while deciding, such as the counter store, a config value or one of your scope resolvers, the request goes through, and the error is reported.
- `DynamicThrottle::fake()` for your app's tests: the counters and the manifest live in memory, so tests need no Redis and each one starts from zero.
