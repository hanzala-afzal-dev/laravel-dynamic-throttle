<p align="center">
  <img src="art/banner.svg" width="100%" alt="Dynamic Throttle for Laravel: inside your Laravel app, Dynamic Throttle is the first stop for requests from IPs, users, tokens, headers and tenants. An even flow goes on to the routes, controllers and database, and the excess gets a 429.">
</p>

<p align="center">
  <a href="https://packagist.org/packages/hanzala-afzal/laravel-dynamic-throttle"><img src="https://img.shields.io/packagist/v/hanzala-afzal/laravel-dynamic-throttle.svg?style=flat-square&label=packagist&include_prereleases" alt="Latest version on Packagist"></a>
  <a href="https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/actions/workflows/ci.yml"><img src="https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/actions/workflows/ci.yml/badge.svg" alt="Tests"></a>
  <a href="https://packagist.org/packages/hanzala-afzal/laravel-dynamic-throttle"><img src="https://img.shields.io/packagist/dt/hanzala-afzal/laravel-dynamic-throttle.svg?style=flat-square" alt="Total downloads"></a>
  <img src="https://img.shields.io/badge/php-8.3%20%7C%208.4%20%7C%208.5-777BB4?style=flat-square&logo=php&logoColor=white" alt="PHP 8.3, 8.4 and 8.5">
  <img src="https://img.shields.io/badge/laravel-12%20%7C%2013-FF2D20?style=flat-square&logo=laravel&logoColor=white" alt="Laravel 12 and 13">
  <a href="LICENSE"><img src="https://img.shields.io/badge/license-MIT-blue.svg?style=flat-square" alt="MIT license"></a>
</p>

> [!IMPORTANT]
> **Public beta.** `0.1.0-beta.1` has everything planned for 0.1.0: the rules engine, the Artisan commands and the dashboard. Names and options may still change before 0.1.0, and the [changelog](CHANGELOG.md) will say how to upgrade. Try it in a staging app first, and tell us what breaks or confuses you: see [Beta: how to help](#beta-how-to-help).

**Dynamic Throttle for Laravel** lets you change your app's rate limits while it runs: per IP, user, API token, header or tenant, with a dry-run mode, exemptions, emergency switches and a dashboard. It is an open-source package for Laravel 12 and 13, built and maintained by [Hanzala Afzal](https://github.com/hanzala-afzal-dev).

## Why

Laravel's `throttle` middleware and `RateLimiter::for()` work well for limits you know up front. In production, you usually learn the right limits later, and in a hurry:

- a partner's integration starts getting 429s, and you need to give it more room today;
- a scraper or a credential-stuffing attack hits `/login` at 2 a.m.;
- you want a stricter limit on an endpoint, but you don't know how many real users it would block.

With Laravel's own tools, each of these is a code change, a review and a deploy, and you still can't see who is being blocked. **Dynamic Throttle makes rate limits data:** rules live in your database, you change them from a dashboard, Artisan or your own code, and every server applies the change within seconds, without touching a route.

## What it adds to Laravel's rate limiting

| You want to | With Laravel's `throttle` | With Dynamic Throttle |
|---|---|---|
| Change a limit | Edit code and deploy | Edit the rule; every server applies it within 2 seconds |
| Try a limit before it blocks anyone | Not possible | Dry-run: the rule counts, never blocks, and reports who it would have blocked |
| See who is blocked right now | Add your own logging | A live feed with IPs, users and routes, and each rule's top offenders |
| Let a partner, an office or a user through | A code change | An exemption, with an end date |
| Lift or loosen every limit during an incident | A deploy | `dynamic-throttle:bypass on --for=30m`, or one click; it ends by itself |
| Limit per IP and per user on the same route | Stack middleware | Rules stack: on Redis, one round trip however many match |
| Limit per tenant or plan | A custom limiter closure | A custom scope, then one rule |
| Know why a request was blocked | Read the code | The Explain page, or `dynamic-throttle:explain` |
| Know who changed a limit | Your Git history | An append-only audit log of every change |
| Keep serving when Redis is down | An exception per request | Requests go through by default, or get a 503 on the rules you choose |

Rules are never read from the database on a request: they are compiled into your cache, and on PHP-FPM shared through APCu.

## What it is not

It is not a web application firewall, bot detection, geo-blocking, or usage metering for billing. It does not replace edge rate limiting (Cloudflare, Nginx, API gateways): keep those for volumetric attacks, and use this package for limits that need to know who the client is.

## The dashboard

It ships with the package, as Horizon's does, at `/dynamic-throttle`, behind your gates. There is nothing to build or install: the package serves its own assets.

<p align="center">
  <img src="art/dashboard-light.png" width="100%" alt="The Overview in the light theme: health, bypass and the scale factor, the rule counts, the last hour's requests, blocks and would-blocks, a traffic chart and the top rules by blocks.">
</p>

<p align="center">
  <img src="art/dashboard-dark.png" width="100%" alt="The Activity page in the dark theme: the live feed of the last hour's blocks, with each one's time, outcome, rule, route, client IP and an Exempt button.">
</p>

- **Overview:** health, the emergency switches, the last hour in numbers, and a traffic chart over an hour, a day or a week.
- **Rules:** search and filter, create from templates, edit, dry-run and promote, with each rule's traffic, offenders, exemptions and history.
- **Exemptions:** who skips which rule, until when.
- **Activity:** who is being blocked right now, from where and on which route, and each rule's top offenders.
- **Explain:** what the rules would do with a request, and why, without counting it.
- **Audit log:** every change, by whom, with the before and after.
- **Settings:** every health check, the configuration your servers run with, and the commands for when the dashboard itself is down.

Viewers see everything; managers also get the buttons that change things. Light and dark themes follow your system.

## Requirements

| | |
|---|---|
| PHP | 8.3, 8.4 or 8.5 |
| Laravel | 12.69+ or 13.30+ |
| Counter store | Redis 6.2+ or Valkey 7.2+ recommended. Memcached and the database store work too. Not `file` or `apc` in production. |
| PHP-FPM | Enable the APCu extension, so a server's workers share the rules. |

The live feed and the offenders need Redis or Valkey. More in [storage and runtimes](docs/guide/storage-and-runtimes.md).

## Installation

**1. Install the beta** with Composer:

```bash
composer require hanzala-afzal/laravel-dynamic-throttle:^0.1@beta
```

**2. Create the tables:**

```bash
php artisan vendor:publish --tag=dynamic-throttle-migrations
php artisan migrate
```

Publishing the config is optional: `php artisan vendor:publish --tag=dynamic-throttle-config`.

**3. Attach the middleware** to the routes rules may limit, in `bootstrap/app.php`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('api', 'dynamic.throttle');
})
```

It runs after Laravel's `auth` middleware, even in a group that lists it first, so user rules see the user. Routes without `dynamic.throttle` are never limited.

**4. Open the dashboard** at `/dynamic-throttle`. Locally, you get straight in. Before you deploy, say who may look and who may change things, in a service provider's `boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewDynamicThrottle', fn (User $user) => $user->is_admin);
Gate::define('manageDynamicThrottle', fn (User $user) => $user->is_admin);
```

Until you define a gate, only the `local` environment gets in. More in [the dashboard](docs/guide/dashboard.md).

**5. Make sure [Laravel's scheduler](https://laravel.com/docs/scheduling#running-the-scheduler) runs.** The package schedules its own upkeep: it recompiles the rules every five minutes and stores the traffic numbers every minute.

Then check your setup:

```bash
php artisan dynamic-throttle:health
```

## Usage

Add your first rule in the dashboard, under **Rules → New rule**: pick a template such as "Login" or "API per user", adjust it, and save. New rules start in dry-run, so you can watch what they would block before they block anything.

You can do the same from code, in a seeder, a migration or `php artisan tinker`:

```php
use Afzal\DynamicThrottle\Models\Rule;

Rule::create([
    'name' => 'API per user',
    'pattern' => 'api/*',
    'scope' => 'user',
    'max_attempts' => 60,
    'window_seconds' => 60,
]);
```

Saving a rule compiles it, and every server applies it within 2 seconds. Each user now gets 60 requests a minute under `/api`. The 61st gets Laravel's own 429, rendered by your exception handler:

```http
HTTP/1.1 429 Too Many Requests
Retry-After: 42
X-RateLimit-Limit: 60
X-RateLimit-Remaining: 0
X-RateLimit-Reset: 1791403200
```

## Rules

| Attribute | Value | Default |
|---|---|---|
| `name` | Up to 100 characters | required |
| `pattern` | A path, with `*` as a wildcard: `login`, `api/*`, `api/*/export` | required |
| `method` | `GET`, `POST`, `PUT`, `PATCH`, `DELETE`, … or `*` | `*` |
| `scope` | Who is counted: `ip`, `user`, `api_token`, `header`, `global` or `custom` | required |
| `scope_parameter` | The header's name for `header`, the resolver's name for `custom` | `null` |
| `max_attempts`, `window_seconds` | The limit: so many requests per so many seconds | required |
| `priority` | Lower runs first | `100` |
| `mode` | `enforce`, or `dry_run` to count without blocking | `enforce` |
| `on_failure` | When storage fails: `open` lets the request through, `closed` answers 503 | `open` |
| `on_missing_identity` | When the scope finds no one, such as a guest under a `user` rule: `ip` counts by IP, `skip` ignores the rule | `ip` |
| `response_override` | A JSON body for the 429 | `null` |
| `enabled` | `false` switches the rule off | `true` |

When several rules match a request, each counts it, and the first enforcing rule over its limit rejects it.

```php
// Five login attempts a minute per IP, with a custom message.
Rule::create([
    'name' => 'Login',
    'method' => 'POST',
    'pattern' => 'login',
    'scope' => 'ip',
    'max_attempts' => 5,
    'window_seconds' => 60,
    'response_override' => ['message' => 'Too many login attempts. Try again in a minute.'],
]);

// A partner's quota, by the key it sends.
Rule::create([
    'name' => 'Partner API',
    'pattern' => 'api/partners/*',
    'scope' => 'header',
    'scope_parameter' => 'X-Partner-Key',
    'max_attempts' => 10000,
    'window_seconds' => 3600,
]);

// One shared limit for an expensive endpoint.
Rule::create([
    'name' => 'Search',
    'pattern' => 'api/search',
    'scope' => 'global',
    'max_attempts' => 500,
    'window_seconds' => 60,
]);
```

### Your own scope

Register a resolver in a service provider's `boot()`, then name it in a `custom` rule:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Illuminate\Http\Request;

DynamicThrottle::scope('tenant', fn (Request $request) => $request->user()?->tenant_id);
```

```php
Rule::create([
    'name' => 'Per tenant',
    'pattern' => 'api/*',
    'scope' => 'custom',
    'scope_parameter' => 'tenant',
    'max_attempts' => 1000,
    'window_seconds' => 60,
]);
```

A resolver that returns `null` finds no one, and the rule's `on_missing_identity` decides. A class that implements `ScopeResolver` works too.

## Dry-run first

Not sure a limit is right? Start the rule in `dry_run` mode. It counts every request but never blocks one, and dispatches `RateLimitWouldBlock` when it would have:

```php
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

$rule = Rule::create([
    'name' => 'Exports',
    'pattern' => 'api/*/export',
    'scope' => 'user',
    'max_attempts' => 10,
    'window_seconds' => 3600,
    'mode' => 'dry_run',
]);

Event::listen(function (RateLimitWouldBlock $event) {
    Log::info("{$event->ruleName} would have blocked {$event->method} {$event->path}");
});
```

Once the numbers look right, enforce it:

```php
$rule->update(['mode' => 'enforce']);
```

More in [dry-run rules](docs/guide/dry-run.md).

## Exemptions

```php
use Afzal\DynamicThrottle\Models\Exemption;

// Your office network, from every rule.
Exemption::forIp('203.0.113.0/24')->because('Office network')->save();

// One user, from one rule, for a day.
Exemption::forUser($user)->forRule($rule)->until(now()->addDay())->because('Data migration')->save();

// A tenant of your custom scope.
Exemption::forCustom('tenant', 'acme')->because('Enterprise plan')->save();
```

## Artisan commands

| Command | What it does |
|---|---|
| `dynamic-throttle:list` | Lists every rule with its id |
| `dynamic-throttle:status` | Shows what the servers apply now, and until when |
| `dynamic-throttle:explain GET /api/orders --user=42` | Shows why a request would be allowed or limited, without counting it |
| `dynamic-throttle:health` | Looks for setup mistakes. `--strict` fails on a warning too, for deploy scripts |
| `dynamic-throttle:bypass on --for=30m` | Lifts every limit, until the end time |
| `dynamic-throttle:scale 2 --for=2h` | Multiplies every limit: `2` doubles them, `0.5` halves them |
| `dynamic-throttle:disable-rule 12`, `enable-rule 12` | Switches one rule off or back on |
| `dynamic-throttle:compile` | Recompiles the rules now (the scheduler also does) |

During an incident:

```bash
php artisan dynamic-throttle:bypass on --for=30m   # every request goes through
php artisan dynamic-throttle:status                # check what the servers apply
php artisan dynamic-throttle:bypass off
```

Bypass and scaling end by themselves: after one hour, unless you pass `--for` or `--forever`. Each change is written to an append-only audit log. When the database and the cache are both down, set `DYNAMIC_THROTTLE_ENABLED=false`. More in [during an incident](docs/guide/incidents.md), and step by step in the [runbook](docs/runbook.md).

## Events

| Event | Dispatched when |
|---|---|
| `RateLimitExceeded` | An enforcing rule answered 429 |
| `RateLimitWouldBlock` | A dry-run rule would have |
| `StorageUnavailable` | The counter store failed, about once per cooldown |
| `RuleChanged` | A rule was created, updated or deleted |

They are dispatched after the response is sent, or after the transaction commits for `RuleChanged`, and carry plain values, so queued listeners work:

```php
use Afzal\DynamicThrottle\Events\RateLimitExceeded;

Event::listen(function (RateLimitExceeded $event) {
    Log::notice("{$event->ruleName} blocked {$event->method} {$event->path} for {$event->retryAfter} s");
});
```

More in [events](docs/guide/events.md).

## Testing your app

Call `DynamicThrottle::fake()` in tests that send requests through the middleware, for example in your base test case's `setUp()`. The counters and the compiled rules then live in memory, so tests need no Redis, and each test starts from zero:

```php
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Models\Rule;

public function test_login_is_limited(): void
{
    DynamicThrottle::fake();

    Rule::create(['name' => 'Login', 'pattern' => 'login', 'scope' => 'ip', 'max_attempts' => 5, 'window_seconds' => 60]);

    $this->post('/login')->assertHeader('X-RateLimit-Remaining', 4);
}
```

## Configuration

The defaults suit most apps. The settings you are most likely to change have environment variables:

| Variable | Default | |
|---|---|---|
| `DYNAMIC_THROTTLE_ENABLED` | `true` | The kill switch |
| `DYNAMIC_THROTTLE_STORE` | your default cache store | The cache store that holds the counters |
| `DYNAMIC_THROTTLE_DRIVER` | `auto` | `redis` or `cache`. `auto` picks Redis on a Redis store |
| `DYNAMIC_THROTTLE_REDIS_CONNECTION` | the store's connection | A dedicated connection with short timeouts is best |
| `DYNAMIC_THROTTLE_PREFIX` | `dt:` | Apps that share one Redis need different prefixes |
| `DYNAMIC_THROTTLE_DB_CONNECTION` | your default connection | Where the package's tables live |
| `DYNAMIC_THROTTLE_MANIFEST_REFRESH` | `2` | Seconds before a server picks up a rule change |
| `DYNAMIC_THROTTLE_MAX_RULES` | `5` | Enforcing rules that may count one request |
| `DYNAMIC_THROTTLE_COOLDOWN` | `10` | Seconds to skip a counter store that failed |
| `DYNAMIC_THROTTLE_TELEMETRY` | `true` | Records each rule's traffic and blocks |
| `DYNAMIC_THROTTLE_SAMPLE_RATE` | `1` | The share of requests telemetry records |

The rest, such as excluded paths and the user scope's guards, is in [`config/dynamic-throttle.php`](config/dynamic-throttle.php).

## Documentation

The [guide](docs/guide/README.md) covers the details:

- [Attaching the middleware](docs/guide/middleware.md): excluded paths, responses, and Laravel's `throttle`
- [When something fails](docs/guide/failures.md): fail open or closed, the cooldown, strict mode
- [Why a request is limited](docs/guide/explain.md) and [health checks](docs/guide/health.md)
- [Traffic and blocks](docs/guide/telemetry.md): what each rule did, per hour
- [The dashboard](docs/guide/dashboard.md): where it is, who gets in, and what it refuses
- [Runbook](docs/runbook.md): what to do when Redis is down, the manifest was evicted, a bad rule blocks `/api`, or an emergency change was left on

## Beta: how to help

`0.1.0-beta.1` is the first public release. The plan:

1. **Betas** (`0.1.0-beta.N`): fixes and changes from your feedback. A change that breaks something you rely on comes with an upgrade note in the [changelog](CHANGELOG.md).
2. **A release candidate** (`0.1.0-RC1`): no new features, fixes only.
3. **0.1.0**, then **1.0** once it has run in real apps for a while.

Composer only installs a beta when you ask for one, with `@beta`, so nobody gets it by accident.

What helps most:

- **Did it install cleanly** on your Laravel and PHP versions, with your cache store (Redis, Valkey, Memcached, database) and your runtime (PHP-FPM, Octane, Vapor)?
- **Did a rule behave differently** from what you expected? `php artisan dynamic-throttle:explain` shows what it did; paste its output.
- **Was anything in the dashboard or the docs unclear?**

Bugs go in [issues](https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/issues/new/choose), questions and ideas in [discussions](https://github.com/hanzala-afzal-dev/laravel-dynamic-throttle/discussions), and security issues privately, as [SECURITY.md](SECURITY.md) explains.

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md). The [changelog](CHANGELOG.md) lists what changed.

## Security

Please do **not** report security issues in public issues. See [SECURITY.md](SECURITY.md) for private reporting.

## License

The MIT License. See [LICENSE](LICENSE). Copyright © 2026 Hanzala Afzal.

This is an independent, community-maintained package. It is not affiliated with, endorsed by, or sponsored by Laravel. "Laravel" is a trademark of its respective owner.
