# Storage and runtimes

## Counter storage

- **Redis 6.2+ or Valkey 7.2+ (recommended).** A request costs one round trip, however many rules match it.
- **Redis Cluster,** or an endpoint that requires a script's keys to share a hash slot, such as ElastiCache Serverless: use a cluster connection (`clusters` in `config/database.php`). Each rule then costs one round trip. Where such an endpoint supports no cluster client, set `driver` to `cache`.
- **Memcached** works through the `cache` driver.
- **The `database` store** on MySQL or PostgreSQL works, but adds database writes to every limited request. On SQLite it can fail under load with "database is locked".
- **Do not use `file` or `apc` in production.** They are not atomic, so concurrent requests can exceed the limit ([SPEC-002](../specs/002-limiter-store-and-cache-driver.md)).

`php artisan dynamic-throttle:health` warns about a store that does not fit ([health checks](health.md)).

## Runtimes

The package runs on PHP-FPM and on Laravel Octane (Swoole, RoadRunner, FrankenPHP).

- **On PHP-FPM, enable the APCu extension** (the extension, not the `apc` cache store). A server's workers then share the rules, instead of each request reading them from the cache, and they share the counter store's cooldown. With 100 rules, that saves about 0.6 ms per request at p95.
- **The apps under one PHP-FPM master share APCu.** Give each app its own `prefix`, even when two are served from one directory, and run apps that do not trust each other under separate masters.

See [ADR-0002](../adr/0002-supported-platforms.md) for the support policy.
