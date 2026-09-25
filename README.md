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
  - Emergency bypass and scaling switch themselves off at the expiry time you set.
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
| Laravel | 12.61.1+ and 13.12+ (the lowest versions CI tests, [ADR-0019](docs/adr/0019-tested-laravel-floors.md)) |
| Counter storage | Redis 6.2+ or Valkey 7.2+ (recommended), or Memcached. The `database` store on MySQL or PostgreSQL works but adds database writes to every limited request. On SQLite it can fail under load with "database is locked". **Do not use `file` or `apc` in production**: they are not atomic, so concurrent requests can exceed the limit ([SPEC-002](docs/specs/002-limiter-store-and-cache-driver.md)). |
| Runtimes | PHP-FPM, Laravel Octane (Swoole, RoadRunner, FrankenPHP) |

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
2. A compiler turns them into a versioned manifest stored in your cache. It is cached again in memory on Octane, or in APCu on PHP-FPM ([ADR-0007](docs/adr/0007-rule-storage-and-distribution.md)).
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
- **Failures.** If the counters, the manifest, a config value or one of your scope resolvers fails, the request goes through, and the error is reported to your exception handler.

### In your tests

Call `DynamicThrottle::fake()` in every test that sends requests through the middleware, for example in your base test case's `setUp()`. The counters and the manifest then live in memory: tests need no Redis, and each one starts from zero. Save rules with the `Rule` model, before or after `fake()`, and assert on the response:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Models\Rule;

DynamicThrottle::fake();

Rule::create(['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]);

$this->post('/login')->assertHeader('X-RateLimit-Remaining', 4);
```

The counters read Laravel's clock, so `$this->travel()` moves their windows.

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
