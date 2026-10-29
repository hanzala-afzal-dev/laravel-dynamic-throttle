# Health checks

The package lets requests through when something is wrong ([ADR-0010](../adr/0010-failure-policy.md)), so a mistake in its setup can switch limits off without a sound. `dynamic-throttle:health` looks for those mistakes:

```bash
php artisan dynamic-throttle:health            # a line per check
php artisan dynamic-throttle:health --strict   # exits 1 on a warning too, for deploy scripts
php artisan dynamic-throttle:health --json
```

Each check is `ok`, `warning`, `error` or `skipped`, with what it found and what to do. The command exits 1 when a check errs, and with `--strict` when one warns too. It changes nothing.

| Check | What it looks for |
|---|---|
| `config` | A config the counter driver cannot be built from, as `status` reports it. The kill switch off. A value the package replaces with its default, such as a `sample_rate` written as a string. An `auto_attach` group or a `middleware.after` entry that names nothing. |
| `store` | What the counter store guarantees. The `file`, `apc` and `octane` stores count per server, so limits multiply by your servers, and `file` and `apc` lose counts under load. The `database` store writes to your database on every limited request. `array` is for tests, and `null` counts nothing. |
| `cache-clear` | The Redis driver in the Redis database of a cache store, which `php artisan cache:clear` flushes, every limit with it. |
| `redis-timeouts` | A Redis connection requests use, for the counters or the manifest, without a timeout, or with one above a second, so a Redis that hangs holds requests that long or more. Without timeouts, phpredis waits PHP's `default_socket_timeout`, 60 seconds by default ([timeouts](storage-and-runtimes.md#timeouts)). |
| `app-key` | No `APP_KEY`, so identities are hashed without a key. |
| `apcu` | PHP-FPM without APCu, so every request reads the rules from the cache first, and an outage costs every request a try. The command line cannot see the web server's PHP, so there it is skipped: the dashboard's Settings page runs the checks in the web server, and shows the answer. |
| `dashboard` | No `viewDynamicThrottle` or `manageDynamicThrottle` gate outside the `local` environment, so nobody can open the dashboard, and visitors get a 403 that says nothing about why ([who gets in](dashboard.md#who-gets-in)). A `Gate::before` callback alone does not count, as for the dashboard itself. |
| `attached` | `dynamic.throttle` on no route, and not global middleware, so no rule applies. |
| `unreached` | Rules that match only routes `dynamic.throttle` never limits, so they never apply: routes without it, such as a login rule while only the `api` group runs it, or paths `except` covers. A rule that matches no route is not named, since its pattern may name a route parameter's value. |
| `except` | Horizon, Telescope, Pulse, Nova or Sanctum at a path `except` does not cover, so a rule can limit them. |
| `two-limiters` | Routes that run Laravel's `throttle` too, which writes the same headers. |
| `middleware-order` | Routes where authentication runs after `dynamic.throttle`, so user rules count your users as guests. |
| `manifest` | No manifest in the cache, so no rule applies. One that the scheduled compile has not rewritten for 10 minutes. More than 500 rules or 1,000 exemptions, which every load of it pays for. |
| `emergency` | Bypass or a scale factor that servers apply with no end time, or with one more than 24 hours away: an emergency change left on. One that ends within 24 hours is ok, so a deploy during an incident passes `--strict`. |
| `metrics` | Metrics not flushed for more than 10 minutes: the scheduler is not running. |
| `guests` | A user rule that saw mostly guests in the last hour, half or more of at least 100 requests recorded: authentication the route check cannot see, or a rule on public routes. |
| `dominant-ip` | One client IP behind half or more of at least 1,000 requests recorded over the current and the previous hour: usually a load balancer's, because the trusted proxies are not set. Redis and Valkey only. |
| `ignored` | Rules ignored in the last hour because more than `matching.max_rules_per_request` rules of their mode matched a request. |

The dashboard's Settings page runs the same checks inside the web server, so its `apcu` line describes the PHP that serves your requests.

The last three read [telemetry](telemetry.md), and are skipped while it is off. For them, the middleware also records per rule and minute the guests a user rule counted, the requests a rule skipped for want of an identity, and those it was ignored for. On Redis and Valkey, it also counts each hour's requests per client, by the hash an `ip` rule gives the client, in the same script call. None of these reach the hourly table.
