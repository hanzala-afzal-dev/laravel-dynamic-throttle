# Guide

The [README](../../README.md) gets you started. These pages go into the details.

## How it works

1. Rules live in your database. You change them in the dashboard, through its JSON API, with Artisan or through the `Rule` model.
2. A compiler turns them into a versioned manifest stored in your cache, after each change commits. Octane keeps it in memory, and on PHP-FPM, a server's workers share it in APCu, so a process or a server reads the cache about once per `manifest.refresh_seconds`. No request ever reads the rules from the database.
3. The `dynamic.throttle` middleware runs after authentication. It matches every applicable rule and counts each request with a sliding-window counter. Blocked requests get a normal Laravel 429.

## Pages

- [Rules](rules.md): the attributes, examples, your own scope, an endpoint that costs money, and exemptions
- [Configuration](configuration.md): the environment variables
- [Attaching the middleware](middleware.md): where it runs, excluded paths, responses and headers
- [Storage and runtimes](storage-and-runtimes.md): which counter store to use, and APCu on PHP-FPM
- [Performance](performance.md): what a request costs, against no limiter and Laravel's `throttle`
- [Dry-run rules](dry-run.md): try a limit on real traffic first
- [When something fails](failures.md): fail open or closed, the cooldown, strict mode
- [Why a request is limited](explain.md): `dynamic-throttle:explain`
- [Health checks](health.md): `dynamic-throttle:health`
- [Traffic and blocks](telemetry.md): what each rule did, per hour
- [Events](events.md): `RateLimitExceeded`, `RateLimitWouldBlock`, `StorageUnavailable`, `RuleChanged`
- [During an incident](incidents.md): bypass, scale, and switching one rule off
- [The dashboard](dashboard.md): where it is, who gets in, and what it refuses
- [Testing your app](testing.md): `DynamicThrottle::fake()`
- [Removing the package](uninstalling.md): in two deploys, what stays, and cleaning up

When something goes wrong in production, the [runbook](../runbook.md) has the steps: Redis is down, the manifest was evicted, a bad rule blocks `/api`, or an emergency change was left on.
