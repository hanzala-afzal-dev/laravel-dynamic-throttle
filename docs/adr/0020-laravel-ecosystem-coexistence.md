# ADR-0020: Coexistence with the Laravel ecosystem

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2–3
- Amends: [ADR-0004](0004-request-pipeline.md), [ADR-0007](0007-rule-storage-and-distribution.md), [ADR-0008](0008-rule-matching.md), [ADR-0009](0009-scopes-and-identity.md), [ADR-0010](0010-failure-policy.md)
- Related: ADR-0005, ADR-0017

## Context

- Host apps run many other packages, and the middleware sits in the request path of all of them:
  - auth: Sanctum, Passport, JWT packages
  - admin tools: Horizon, Telescope, Pulse, Nova
  - Livewire and multi-tenancy packages
  - their own rate limits

  ADR-0010's principle is that the package must never take the app down.
- The accepted ADRs were checked against Laravel 12.61 and 13. They leave places where the package can still break an app, or misbehave without anyone noticing:
  1. **Config mistakes throw.** Each of these throws `InvalidArgumentException` when the counter store is built:
     - an unknown `driver`
     - a cache `store` that is not defined
     - an invalid `prefix`

     ADR-0010 only promises that *storage* exceptions never escape the middleware. On the request path, one typo would mean a 500 on every request.
  2. **`cache:clear` resets every limit.** On a Redis cache store, `cache:clear` runs `FLUSHDB` on the store's connection. By default, the Redis driver shares that connection and database: Laravel's `cache` connection, database 1. So `cache:clear` and `optimize:clear`, which many deploy scripts run, wipe the counters and the manifest.
  3. **Other packages' routes get throttled.** ADR-0004 protects only our own dashboard. With `auto_attach` on `web`, a broad rule also throttles:
     - Horizon, Telescope, Pulse and Nova, which use the `web` group by default
     - Livewire's single update endpoint, which carries every component
     - `broadcasting/auth` and `sanctum/csrf-cookie`

     During an incident, admins could lose Horizon.
  4. **Auth middleware that Laravel cannot see.** Priority insertion (ADR-0004) relies on Laravel's `AuthenticatesRequests` interface. Some JWT packages and hand-written API-key middleware do not implement it, so our middleware can run first, and user rules silently become IP rules. ADR-0004 only detects this afterwards, through a health check.
  5. **Guards.** The `user` scope reads `$request->user()`: the default guard, unless an auth middleware switched it. On routes where token auth is optional (Sanctum, Passport), token users count as guests.
  6. **Equal priorities.** ADR-0008 orders rules by priority, but not rules that share one, so their order could change from one compile to the next.
  7. **Table names.** The `throttle_*` names (ADR-0007) are generic enough to collide with another package or an app table. Renaming tables later is a breaking schema change (ADR-0017).
  8. **Multi-tenancy.** Tenancy packages re-prefix or tag the cache and Redis per tenant, and switch database connections. Counters, the manifest and the compiler would silently follow the current tenant. No ADR decides this.
  9. **Two limiters on one route.** Laravel's `throttle` middleware and ours both write the `X-RateLimit-*` headers, so the one that runs last wins.
  10. **Config upgrades.** The two merge helpers each lose something:
      - `mergeConfigFrom` merges only top-level keys. An app that published the 0.1 config would silently lose every nested default added later, such as `middleware.auto_attach` or `failure.cooldown_seconds`.
      - `replaceConfigRecursivelyFrom` keeps nested defaults, but merges lists by index. A user who removes an entry from a default list gets it back.

## Decision

1. **Only the rejection leaves the middleware.** This extends ADR-0010 point 6 from storage to every exception the package raises on the request path:
   - storage errors
   - invalid config
   - the app's own scope resolvers
   - a corrupt manifest

   The middleware catches these and applies the failure policy: fail-open by default, 503 for fail-closed rules. Only the 429 (`ThrottleRequestsException`) and the 503 leave it.
   - It reports each caught error through Laravel's `report()`, so the app's error tracker (Sentry, Flare, Bugsnag, Nightwatch) sees it, not just the log. The ADR-0010 cooldown keeps that from flooding the tracker.
   - **Strict mode**, opt-in and off by default, follows the style of `Model::shouldBeStrict()`: for example, `DynamicThrottle::strict(! app()->isProduction())`. It rethrows config errors and errors from the app's resolvers, because those are programming mistakes that should fail loudly in development. Storage errors follow the failure policy even in strict mode.
   - Config is also checked where a mistake is cheap to see: `php artisan about`, the health check, and `dynamic-throttle:status`, which exits non-zero so deploy scripts can stop on it.
2. **Counters survive `cache:clear`.** Defaults stay as they are, so a fresh app works without config changes. Instead:
   - The docs recommend a dedicated Redis connection on its own database for the Redis driver, with a ready-to-copy `dynamic-throttle` connection for `config/database.php`.
   - Laravel's own `throttle` also resets on `cache:clear`, so this default matches Laravel.
   - The health check warns when the counters share a database with a cache store.
   - The manifest stays in the cache store. After a flush it rebuilds itself (ADR-0007).
3. **An `except` list.** Config `except` holds path patterns (`Str::is`, like rules) that no rule ever applies to. They are checked before matching.
   - Defaults: `horizon/*`, `telescope/*`, `pulse`, `pulse/*`, `nova/*`, `nova-api/*`, `livewire/*`, `broadcasting/auth`, `sanctum/csrf-cookie`, `up`.
   - The dashboard stays protected in code (ADR-0004 point 5), not through this list, so editing the list can never lock admins out.
   - The health check reports installed packages whose configured path is missing from `except`, such as `horizon.path`.
   - A rule never overrides `except`. To throttle an excluded path, remove it from the list.
   - Livewire posts every component to one endpoint, so no path rule can target a single component, such as a login form. The docs say to limit those inside the component with Laravel's `RateLimiter`, as Breeze and Jetstream do. Apps that want a coarse limit on all Livewire traffic remove `livewire/*` from the list.
4. **Declaring other auth middleware.** Config `middleware.after` (default `[]`) lists extra middleware aliases or classes. The package sorts its middleware after them, as it does after Laravel's auth middleware. ADR-0004's health check stays as the safety net.
5. **Guards for the `user` scope.**
   - Config `scopes.user.guards` defaults to `null`: the guard the request authenticated with, as today.
   - A list, such as `['sanctum', 'web']`, tries each guard in order.
   - Trying a guard that has not authenticated the request yet costs that guard's own lookup, such as Sanctum's token query. The docs say so, and the default never triggers one.
   - For token clients, the docs point to the `api_token` scope first: it needs no lookup at all.
6. **Deterministic order.** Rules with the same priority run in ascending id order. The compiler fixes the order (ADR-0008).
7. **Distinctive table names and a configurable connection**, as Telescope and Pulse do:
   - The tables become `dynamic_throttle_rules`, `dynamic_throttle_exemptions`, `dynamic_throttle_settings`, `dynamic_throttle_audit_log` and `dynamic_throttle_metrics_hourly`.
   - Config `database.connection` (default `null`: the app's default connection) chooses the connection they use.
   - Migrations are published with `publishesMigrations()`, which gives them fresh timestamps, and are never loaded automatically. The app owns its schema, and published migrations are never edited (ADR-0017).
8. **Multi-tenancy in v1: one set of rules per app.**
   - Rules live on the configured connection (point 7). Counters live on the configured cache store or Redis connection.
   - The docs show how to keep both out of tenancy bootstrappers.
   - Per-tenant limits are identities, not rules: a `custom:tenant` scope (ADR-0009) counts each tenant separately under one rule.
9. **Two limiters on one route.** The health check lists routes that use both Laravel's `throttle` and ours, and the docs say to pick one (ADR-0004 point 7).
10. **Config merging.** The provider merges config with its own small helper, which handles both cases the Laravel helpers split:
    - Nested maps are merged recursively, so new defaults appear after an upgrade.
    - Lists such as `except`, `auto_attach` and `guards` are replaced as a whole, so removed entries stay removed.

    The helper ships before any nested config key does, in the first Phase 2 spec. Once users have published a config, it is too late.
11. **Nothing changes until the app opts in.** `middleware.auto_attach` defaults to `[]`, and the package registers no global middleware, so installing it changes no request.
12. **A test helper for host apps.** `DynamicThrottle::fake()` swaps the counters for an in-memory store, like Laravel's other fakes. Tests can then assert that a route was throttled or allowed.

## Reasoning

- A rate limiter is an add-on. Wherever it fails, the host app must keep serving, so the failure policy has to cover every failure, not only storage.
- Admin tools, Livewire and auth endpoints are infrastructure other packages rely on. Throttling them by accident hurts more than it protects, and the list stays editable.
- Priority and guards follow Laravel's own mechanisms (the priority list, `shouldUse`), extended by configuration instead of guesses about other packages.
- Fixed, distinctive table names plus a configurable connection is the pattern of Laravel's first-party packages.
- One merge helper fixes both upgrade traps, with no changes needed from users.

## Alternatives considered

- **Throw on bad config at boot.** Typos surface at deploy, but one bad env var takes a server down, and every console command fails with it. Rejected. Console commands and strict mode surface the problem instead.
- **A default Redis database for the limiter.** A fresh app has no such connection, so the defaults would fail. Rejected in favor of the warning.
- **Resolving other packages' paths on the request path**, from `horizon.path` and similar keys. That couples the request path to their config keys. Only the health check reads them.
- **Configurable table names**, as spatie/laravel-permission does. Every model and migration would read config, and published migrations would depend on config at migrate time. Rejected.
- **Per-tenant rules.** They need per-tenant manifests, compiles and dashboards. Deferred until users ask.

## Fallback

- If the `except` defaults surprise users (someone expected a `*` rule to cover Livewire), they remove entries from the config. **Revisit when:** issues ask to throttle one of the defaults.
- If catching every exception hides real bugs, the logs, the event and the health check keep them visible. **Revisit when:** a bug report shows a failure that stayed silent.

## Consequences

- Good: installing the package cannot break other packages' routes, and config mistakes cannot take the app down.
- Good: upgrades keep new defaults without users editing their published config.
- Bad: more configuration (`except`, `middleware.after`, `scopes.user.guards`, `database.connection`) and more health checks to maintain.
- Bad: the cache driver goes through Laravel's cache repository, so Telescope, Pulse and Debugbar see its reads, as they see Laravel's own throttle. The docs show their ignore settings.

## Verification

- Point 1: feature tests for an unknown driver, an undefined store, an invalid prefix, a throwing resolver and a corrupt manifest.
  - Each request passes (fail-open), with one `report()` call per cooldown.
  - A fail-closed rule answers 503.
  - In strict mode, config and resolver errors are rethrown, and a storage error still fails open.
  - `dynamic-throttle:status` exits non-zero.
- Point 2: a health-check test for the shared-database warning.
- Point 3: tests with a `*` rule:
  - `livewire/update` and `horizon/*` are never throttled.
  - Removing an entry makes that path throttled.
  - With an empty list, the dashboard stays unthrottled.
- Point 4: a custom auth middleware listed in `middleware.after`, declared after ours in a group, still runs before ours.
- Point 5: with guards `['sanctum', 'web']`, a token user on a route without auth middleware counts as that user. With the default, a query-count assertion shows no extra query.
- Point 6: two compiles of rules with equal priorities give the same order, by id.
- Point 7: the migrations create the `dynamic_throttle_*` tables on the configured connection.
- Point 9: a health-check test for a route with both limiters.
- Point 10: a provider test:
  - A config published by an older version gets the new nested defaults.
  - A list the user shortened stays short.
- Point 11: with the default config and a `*` rule, no route is throttled.
- Point 12: a test app asserts that a route was throttled through `DynamicThrottle::fake()`.

## Decision record

Proposed and accepted on 2026-09-24. The maintainer accepted every point, with refinements added before acceptance:
- `report()` and strict mode (point 1)
- the ready-to-copy connection (point 2)
- the no-override rule and the Livewire caveat (point 3)
- `api_token` first (point 5)
- published migrations (point 7)
- the helper shipping first (point 10)
- points 11 and 12
