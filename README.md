# Laravel Dynamic Throttle

**Change rate limits at runtime, per user or token, and dry-run them first.**

[![CI](https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/actions/workflows/ci.yml/badge.svg)](https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/actions/workflows/ci.yml)
[![License: MIT](https://img.shields.io/badge/license-MIT-blue.svg)](LICENSE)

> [!WARNING]
> **Early development.** The package is in Phase 2 (rules and middleware) of the [roadmap](docs/roadmap.md).
> Nothing is released yet, and it is not ready for production.
> The first public release, 0.1.0, arrives at the end of Phase 3 as a CLI-only version.

## What it is

Laravel's built-in rate limiting lives in code: changing a limit means a code change and a deploy. This package is an application-level **rate-limit control plane** for Laravel ([ADR-0001](docs/adr/0001-scope-and-positioning.md)):

- **Rules are data.** They are stored in your database, changed at runtime, and applied without a deploy or route changes.
- **Identity-aware.** Limit by IP (IPv6 grouped by /64), authenticated user, API token, a trusted header, globally, or by your own resolver (plan, tenant, organization…).
- **Dry-run first.** Try a limit on real traffic and see who *would* be blocked before you enforce it.
- **Safe by default.**
  - Rules are never read from the database per request. They are compiled into a manifest in your cache. With Laravel's `database` cache store, the counters and the manifest themselves live in the database, so use Redis, Valkey or Memcached for busy apps.
  - If storage fails, requests are allowed by default, and you can make a sensitive rule fail closed instead.
  - Emergency bypass and scaling switch themselves off at their end time, an hour after you set them unless you choose another.
  - An environment kill switch works even when the database and cache are down.
- **Operable.** It comes with Artisan commands, a JSON API, an append-only audit log, and a [minimal dashboard](docs/adr/0018-minimal-dashboard.md) (server-rendered, strict CSP, no JavaScript build step).

## What it is not

Non-goals for v1:
- a web application firewall (SQLi/XSS detection)
- bot detection or geo-blocking
- usage metering or billing quotas
- limits for queue jobs or outgoing HTTP calls
- one dashboard controlling several apps

It does **not** replace edge rate limiting (Cloudflare, Nginx, API gateways). Keep edge limits for volumetric attacks, and use this package for limits that need to know *who* the client is.

## Requirements

| | Supported |
|---|---|
| PHP | 8.3, 8.4, 8.5 |
| Laravel | 12.69+ and 13.30+ (the lowest versions CI tests, [ADR-0019](docs/adr/0019-tested-laravel-floors.md)) |
| Counter storage | Redis 6.2+ or Valkey 7.2+ (recommended), or Memcached. On Redis, a request costs one round trip, however many rules match it. On Redis Cluster, or on an endpoint that requires a script's keys to share a hash slot, such as ElastiCache Serverless, use a cluster connection (`clusters` in `config/database.php`): each rule then costs one round trip. Where such an endpoint supports no cluster client, set `driver` to `cache`. The `database` store on MySQL or PostgreSQL works but adds database writes to every limited request. On SQLite it can fail under load with "database is locked". **Do not use `file` or `apc` in production**: they are not atomic, so concurrent requests can exceed the limit ([SPEC-002](docs/specs/002-limiter-store-and-cache-driver.md)). |
| Runtimes | PHP-FPM, Laravel Octane (Swoole, RoadRunner, FrankenPHP). **On PHP-FPM, enable the APCu extension** (the extension, not the `apc` cache store): a server's workers then share the rules, instead of each request reading them from the cache, and the counter store's cooldown. With 100 rules, that saves about 0.6 ms per request at p95. The apps under one PHP-FPM master share APCu: give each app its own `prefix`, even when two are served from one directory, and run apps that do not trust each other under separate masters. |

Composer enforces the PHP and Laravel versions when you install. See [ADR-0002](docs/adr/0002-supported-platforms.md) for the support policy.

## Installation

Not on Packagist yet. Once 0.1.0 is released:

```bash
composer require hanzala-afzal/laravel-dynamic-throttle
```

The service provider is auto-discovered. To publish the config file:

```bash
php artisan vendor:publish --tag=dynamic-throttle-config
```

## How it works

1. Admins edit rules in the database, through the dashboard, the JSON API or Artisan.
2. A compiler turns them into a versioned manifest stored in your cache. Octane keeps it in memory, and on PHP-FPM, a server's workers share it in APCu, so a process or a server reads the cache about once per `manifest.refresh_seconds` ([ADR-0007](docs/adr/0007-rule-storage-and-distribution.md)).
3. The `dynamic.throttle` route middleware runs after authentication. It matches every applicable rule and counts each request with a sliding-window counter ([ADR-0006](docs/adr/0006-rate-limit-algorithm.md)). Blocked requests get a normal Laravel 429.

The design is documented as [Architecture Decision Records](docs/adr/README.md). Feature specs live in [docs/specs](docs/specs).

## Usage

### Attach the middleware

Rules apply only to routes that run the `dynamic.throttle` middleware, and installing the package attaches it nowhere ([ADR-0020](docs/adr/0020-laravel-ecosystem-coexistence.md)). Add it to your route groups in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', 'dynamic.throttle');
})
```

Or list the groups in the `middleware.auto_attach` config key, such as `['api']`. **A route outside those groups is never limited.**

- **After authentication.** The middleware runs after Laravel's authentication middleware, `auth` and `auth.basic`, even in a group that lists it first, so user rules see the user. If users log in through middleware that Laravel cannot recognize, such as a JWT package's or your own API key check, list it in `middleware.after`, by alias or class. Until you do, user rules count those users as guests.
- **Excluded paths.** No rule ever applies to the paths in the `except` config key, not even a `*` rule. By default, they are Horizon, Telescope, Pulse, Nova, Livewire 3 and 4, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`. Remove an entry to limit its path.
- **Responses.** A limited request gets Laravel's own 429 (`ThrottleRequestsException`), rendered by your exception handler, with `Retry-After`, `X-RateLimit-Reset`, `X-RateLimit-Limit` and `X-RateLimit-Remaining: 0`. A rule's `response_override` replaces the body with its JSON. An allowed request gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from its most restrictive enforcing rule. Dry-run rules never set headers.
- **Laravel's `throttle` on the same route.** Both limit the request, and whichever runs last writes the `X-RateLimit-*` headers. Pick one per route.
- **Failures.** If the counters, the manifest, a config value or one of your scope resolvers fails, the request goes through, and the error is reported to your exception handler. A rule can answer 503 instead: see [When something fails](#when-something-fails).

### Dry-run rules

A rule whose `mode` is `dry_run` counts requests exactly as an enforcing rule does, but never rejects one and sets no headers. When it would have rejected a request, the middleware dispatches `RateLimitWouldBlock` after the response has been sent, so a listener never slows the response ([ADR-0012](docs/adr/0012-dry-run.md)):

```php
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

Event::listen(function (RateLimitWouldBlock $event) {
    Log::info("{$event->ruleName} would have blocked {$event->method} {$event->path}", [
        'rule' => $event->ruleId,
        'client' => $event->identityHash,
        'retry_after' => $event->retryAfter,
    ]);
});
```

- **When.** It fires on exactly the requests an enforcing copy of the rule would reject: one event for each dry-run rule that would block, in the order the rules run.
- **What.** Plain values, so a queued listener works too: the rule's `ruleId`, `ruleName`, `limit`, `windowSeconds` and `scope`, the client's `identityHash`, the `retryAfter` it would have got, and the request's `method` and `path`. The client appears only as the hash its counter uses. A listener that needs the IP or the user reads them from the request.
- **Failures.** A listener that throws is reported to your exception handler, and the other events are still dispatched.
- **Tests.** `Event::fake()` records it like any other event.

### When something fails

A rule's `on_failure` decides what happens when its counter cannot be checked: the counter store fails, or the rule cannot tell who the client is, such as when your custom scope resolver throws ([ADR-0010](docs/adr/0010-failure-policy.md)).

- **`open`, the default.** The request goes through. Only the rules that counted it before the failure set rate-limit headers.
- **`closed`.** The request gets a 503 (`ServiceUnavailableHttpException`), which your exception handler renders as it renders `abort(503)`, with a `Retry-After`. Use it for a rule that must hold during an outage, such as one on a login route. A dry-run rule never answers 503. While the cache cannot be read, the rules still apply from the last manifest the process read, or on PHP-FPM with APCu, the last one the server read. Only with none is no rule known, so none answers 503.
- **The cooldown.** After the counter store fails, the package skips it for `failure.cooldown_seconds` (`DYNAMIC_THROTTLE_COOLDOWN`, default 10, and 0 turns it off). An outage then costs one failed call per cooldown instead of one per request: meanwhile, fail-open rules let requests through at once, and fail-closed rules answer 503 at once, with `Retry-After` set to what is left of the cooldown. The cooldown lives in the process, for Octane and queue workers, and in APCu when the extension is enabled, where a server's PHP-FPM workers share it. **On PHP-FPM, enable APCu**: without it, each request tries the store again.
- **Reports.** Every failure is reported to your exception handler, but each cause at most once per cooldown, so an outage or a broken resolver cannot flood your error tracker. A cause is the exception's class and where it was thrown. A config mistake, such as an unknown `driver`, counts as a store that failed.

When the counter store fails, the middleware dispatches `StorageUnavailable` after the response, at most once per request, and so about once per cooldown. It carries plain values, so a queued listener works too: the error's `exceptionClass` and `message`, whether it failed on a `release`, and the `cooldownSeconds`.

```php
use Afzal\DynamicThrottle\Events\StorageUnavailable;

Event::listen(function (StorageUnavailable $event) {
    Log::warning("Rate limiting is degraded: {$event->message}");
});
```

**Strict mode.** Programming mistakes are reported like any other failure. To have them thrown in development instead, turn on strict mode in a service provider's `boot()`:

```php
DynamicThrottle::strict(! app()->isProduction());
```

The middleware then rethrows config mistakes, such as an unknown `driver`, and errors from your own code, such as a custom scope resolver or a guard that throws. Storage errors, the manifest and a client IP that cannot be read still follow `on_failure`, since no code can prevent them.

### Why a request is limited

`dynamic-throttle:explain` shows what the middleware does with a request, and why, without counting it ([ADR-0008](docs/adr/0008-rule-matching.md)):

```bash
php artisan dynamic-throttle:explain GET /api/orders --ip=203.0.113.7 --user=42 --header="X-Api-Key: abc"
```

- **The rules** the request matches, in the order they run, each with its limit under the scale factor. For each, who the client counts as: the identity as given, such as `ip:203.0.113.7`, and the hash its counter and `RateLimitWouldBlock` use. A rule that does not count the request says why: an exemption, no identity, the cap, a scope that failed, or an earlier fail-closed rule.
- **The counters**, on the Redis and cache drivers: the count in the sliding window, what remains, and whether the next request goes through, with its `Retry-After`. A custom `LimiterStore` shows the rules without counters.
- **The decision** the next request gets, with its headers: allowed, the 429 of the rule that rejects it, or the 503 of a fail-closed rule. Or why it goes through untouched: the route does not run `dynamic.throttle`, `enabled` is off, `except` lists the path, or bypass is on.
- **The route's middleware**, in running order, and whether `dynamic.throttle` runs after authentication, so you can see whether user rules see the user.
- **Inputs.** `--ip` is the client's IP as your app sees it, after its trusted proxies, and 127.0.0.1 by default. `--user` takes a user's id, which the user provider of `--guard`, or of the default guard, looks up. `--header`, repeatable, feeds the `header` and `api_token` scopes and your custom resolvers, which receive the simulated request.
- **For scripts.** `--json` prints the same explanation as one JSON object, and an error as `{"error": "…"}`.
- **It changes nothing.** It reads the counters and the manifest, and never counts, writes or compiles. It decides as a server out of its cooldown does, and other requests can count between it and the next one.

### During an incident

Two runtime settings change every rule at once, without a deploy, and end by themselves ([ADR-0011](docs/adr/0011-emergency-controls.md)). They need the package's newest migrations: publish them and run `php artisan migrate`.

```bash
php artisan dynamic-throttle:bypass on --for=30m   # every request goes through, unlimited
php artisan dynamic-throttle:scale 2 --for=2h      # every limit doubles; 0.5 halves them
php artisan dynamic-throttle:status                # what servers apply, and until when
php artisan dynamic-throttle:bypass off
php artisan dynamic-throttle:scale off
```

- **End times.** `--for` takes `90s`, `30m`, `2h` or `1d`, up to 365 days. Without it, a change ends after one hour, and `--forever` sets no end time. Servers stop applying a setting at its end time, by their own clock, even when nothing compiles in between.
- **Scaling.** The factor goes from 0.1 to 10, with at most two decimals. A rule's limit becomes `max(1, ceil(max_attempts × factor))`, a dry-run rule's too, and the counts made before the change still count.
- **One bad rule.** `php artisan dynamic-throttle:list` shows every rule with its id. `dynamic-throttle:disable-rule 12` switches rule 12 off, and `enable-rule 12` brings it back.
- **Confirmation.** In production, turning bypass on, a factor below 1 and disabling an enforcing rule ask first. Scripts pass `--force`.
- **Propagation.** Each change compiles the manifest, and servers apply it within `manifest.refresh_seconds`. If the manifest cannot be written, the change stays saved, the command exits 1, and servers keep the previous manifest until a compile succeeds.
- **The audit log.** Each change writes one append-only entry to `dynamic_throttle_audit_log`: the actor (`cli:` and the system user), the action, the target, and the state before and after.
- **In deploy scripts.** `dynamic-throttle:status` exits 1 when the config is invalid, such as an unknown `driver`, an undefined `store` or an invalid `prefix`.
- **The last resort.** When the database and the cache are both down, set `DYNAMIC_THROTTLE_ENABLED=false` and reload the config: the middleware then does nothing.

### In your tests

Call `DynamicThrottle::fake()` in every test that sends requests through the middleware, for example in your base test case's `setUp()`. The counters and the manifest then live in memory: tests need no Redis, and each one starts from zero. Save rules with the `Rule` model, before or after `fake()`, and assert on the response:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Models\Rule;

DynamicThrottle::fake();

Rule::create(['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]);

$this->post('/login')->assertHeader('X-RateLimit-Remaining', 4);
```

The counters read Laravel's clock, so `$this->travel()` moves their windows. The fake store never fails, and each `fake()` starts without a cooldown.

## Development

The suite runs in Docker, so you need no local PHP 8.3:

```bash
docker compose run --rm php composer update   # install dependencies
docker compose run --rm php composer test     # Pest
docker compose run --rm php composer analyse  # Larastan
docker compose run --rm php composer format   # Pint

docker compose up -d                          # Redis, Valkey, Memcached, MySQL, PostgreSQL
docker compose run --rm php composer test:drivers
```

With PHP 8.3+ installed locally, run the same `composer` commands directly. See [CONTRIBUTING.md](CONTRIBUTING.md) for the workflow.

## Security

Please do **not** report security issues in public issues. See [SECURITY.md](SECURITY.md) for private reporting.

## License

Released under the [MIT License](LICENSE). Copyright © 2026 Hanzala Afzal.

This is an independent, community-maintained package. It is not affiliated with, endorsed by, or sponsored by Laravel. "Laravel" is a trademark of its respective owner.
