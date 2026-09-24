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
