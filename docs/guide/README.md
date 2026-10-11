# Guide

The [README](../../README.md) gets you started. These pages go into the details.

## How it works

1. Rules live in your database. You change them through the `Rule` model or Artisan, and, from the first release, in the dashboard and through its JSON API.
2. A compiler turns them into a versioned manifest stored in your cache, after each change commits. Octane keeps it in memory, and on PHP-FPM, a server's workers share it in APCu, so a process or a server reads the cache about once per `manifest.refresh_seconds` ([ADR-0007](../adr/0007-rule-storage-and-distribution.md)). No request ever reads the rules from the database.
3. The `dynamic.throttle` middleware runs after authentication. It matches every applicable rule and counts each request with a sliding-window counter ([ADR-0006](../adr/0006-rate-limit-algorithm.md)). Blocked requests get a normal Laravel 429.

## Pages

- [Attaching the middleware](middleware.md): where it runs, excluded paths, responses and headers
- [Storage and runtimes](storage-and-runtimes.md): which counter store to use, and APCu on PHP-FPM
- [Dry-run rules](dry-run.md): try a limit on real traffic first
- [When something fails](failures.md): fail open or closed, the cooldown, strict mode
- [Why a request is limited](explain.md): `dynamic-throttle:explain`
- [Health checks](health.md): `dynamic-throttle:health`
- [Traffic and blocks](telemetry.md): what each rule did, per hour
- [Events](events.md): `RateLimitExceeded`, `RateLimitWouldBlock`, `StorageUnavailable`, `RuleChanged`
- [During an incident](incidents.md): bypass, scale, and switching one rule off
- [Testing your app](testing.md): `DynamicThrottle::fake()`

When something goes wrong in production, the [runbook](../runbook.md) has the steps: Redis is down, the manifest was evicted, a bad rule blocks `/api`, or an emergency change was left on.

The design is recorded as [Architecture Decision Records](../adr/README.md), and each feature has a [spec](../specs).
