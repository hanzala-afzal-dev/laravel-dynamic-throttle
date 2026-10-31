# Storage and runtimes

## Counter storage

- **Redis 6.2+ or Valkey 7.2+ (recommended).** A request costs one round trip, however many rules match it.
- **Redis Cluster,** or an endpoint that requires a script's keys to share a hash slot, such as ElastiCache Serverless: use a cluster connection (`clusters` in `config/database.php`). Each rule then costs one round trip. Where such an endpoint supports no cluster client, set `driver` to `cache`.
- **Memcached** works through the `cache` driver.
- **The `database` store** on MySQL or PostgreSQL works, but adds database writes to every limited request. On SQLite it can fail under load with "database is locked".
- **Do not use `file` or `apc` in production.** They are not atomic, so concurrent requests can exceed the limit.

`php artisan dynamic-throttle:health` warns about a store that does not fit ([health checks](health.md)).

## Timeouts

A Redis that is down fails at once, and requests go through ([when something fails](failures.md)). A Redis that hangs, such as one that is overloaded or failing over, accepts the connection and never answers. Each call then waits for the connection's timeout before it fails, and the request waits with it.

Laravel's Redis connections set no timeout by default. phpredis then waits PHP's `default_socket_timeout`, 60 seconds unless you changed it. Predis waits 5 seconds to connect and `default_socket_timeout` for each reply. A busy server runs out of PHP workers long before that, and the whole app stops answering.

Give the package a Redis connection of its own, with timeouts of a fraction of a second:

```php
// config/database.php, under 'redis'
'throttle' => [
    'url' => env('REDIS_URL'),
    'host' => env('REDIS_HOST', '127.0.0.1'),
    'username' => env('REDIS_USERNAME'),
    'password' => env('REDIS_PASSWORD'),
    'port' => env('REDIS_PORT', '6379'),
    'database' => env('REDIS_THROTTLE_DB', '2'),
    'timeout' => 0.25,            // to connect, in seconds
    'read_timeout' => 0.25,       // for each reply, with phpredis
    'read_write_timeout' => 0.25, // for each reply, with Predis
    'max_retries' => 0,           // fail at once instead of reconnecting
],
```

```dotenv
DYNAMIC_THROTTLE_REDIS_CONNECTION=throttle
```

The manifest stays in your cache store, so give that store's connection timeouts too:

```php
// config/database.php, under 'redis': your cache store's connection, "cache" by default
'cache' => [
    // ... the settings it has, and:
    'timeout' => 0.25,
    'read_timeout' => 0.25,
    'read_write_timeout' => 0.25,
],
```

- **The counters** then hold a request about 0.25 seconds when Redis hangs. The request goes through, or gets a 503 on a fail-closed rule, and the [cooldown](failures.md) skips Redis for the next 10 seconds: in the process, or with APCu, in every PHP-FPM worker of the server.
- **Reconnects and retries repeat the wait.** After a failed call, Laravel's phpredis connection reconnects, and on a connection that selects a `database` or authenticates, that waits the read timeout too: twice the timeout in all, as on an Octane worker whose connection was open when Redis hung. A cluster client also tries each seed node as it reconnects, and Predis's cluster client retries a timed-out command up to 5 times, so give clusters the shortest timeouts you can. Predis on a single connection waits once.
- **A database of its own** also keeps `php artisan cache:clear` from wiping the counters (the `cache-clear` check).
- **The manifest** stays in your cache store, so a request that reads it waits on that store's connection: once per `manifest.refresh_seconds` per server with APCu or on Octane, and on every request without APCu. Give that connection timeouts too, since your app's own cache calls hang with it. With phpredis, Laravel retries a read once after a lost connection, so reading the manifest can wait twice the timeout. A process, or with APCu a server, that has not read the manifest yet, such as one started during the hang, tries it on every request.
- **`php artisan dynamic-throttle:health`** shows the timeouts of each connection requests use, and warns about one above a second, or none (the `redis-timeouts` check).

The package does not shorten your connections' timeouts itself: the right value depends on your network, and the connection may be one your app uses too.

## Runtimes

The package runs on PHP-FPM and on Laravel Octane (Swoole, RoadRunner, FrankenPHP).

- **On PHP-FPM, enable the APCu extension** (the extension, not the `apc` cache store). A server's workers then share the rules, instead of each request reading them from the cache, and they share the counter store's cooldown. With 100 rules, that saves about 0.6 ms per request at p95. The dashboard's Settings page warns when the web server's PHP runs without it (the `apcu` check); `dynamic-throttle:health` in the command line cannot tell, since APCu is usually off there.
- **The apps under one PHP-FPM master share APCu.** Give each app its own `prefix`, even when two are served from one directory, and run apps that do not trust each other under separate masters.

**Support policy:** a new Laravel major is supported within about four weeks of its release. A PHP or Laravel version is dropped only after it reaches its end of life, and only in a new major version of the package, or a new minor version while it is before 1.0.
