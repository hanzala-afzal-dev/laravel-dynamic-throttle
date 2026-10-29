<?php

declare(strict_types=1);

use Illuminate\Support\Env;

return [

    /*
    |--------------------------------------------------------------------------
    | Kill switch
    |--------------------------------------------------------------------------
    |
    | When false, the package's middleware does nothing at all. This keeps
    | working when the database and the cache are both down, but it needs a
    | config reload to take effect. For fast, reversible changes during an
    | incident, use the runtime emergency controls instead (docs/adr/0011).
    |
    | "false", "0", "off" and "no" all disable the package.
    |
    */

    'enabled' => filter_var(env('DYNAMIC_THROTTLE_ENABLED', true), FILTER_VALIDATE_BOOL),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | Rules apply only to routes that run the "dynamic.throttle" middleware.
    | The package attaches it nowhere by itself: add it to your route groups,
    | for example in bootstrap/app.php:
    |
    |     ->withMiddleware(function (Middleware $middleware) {
    |         $middleware->appendToGroup('web', 'dynamic.throttle');
    |         $middleware->appendToGroup('api', 'dynamic.throttle');
    |     })
    |
    | or list the groups in "auto_attach", such as ['web', 'api']. A route
    | in no group that runs the middleware is never limited. Login, sign-up
    | and password reset routes are in "web".
    |
    | The middleware always runs after Laravel's authentication middleware,
    | auth and auth.basic, so user rules see the user. List any other
    | middleware that logs users in, such as a JWT package's or your own
    | API key check, in "after", by alias or class: until you do, user
    | rules count those users as guests.
    |
    */

    'middleware' => [
        'auto_attach' => [],
        'after' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Excluded paths
    |--------------------------------------------------------------------------
    |
    | No rule ever applies to these paths, even a "*" rule. Patterns work as
    | in rules: "*" matches any characters, and a leading slash is ignored.
    | The defaults keep admin tools, Livewire and auth endpoints that other
    | packages rely on reachable (docs/adr/0020). Remove an entry to limit
    | its path. The package's own dashboard is never limited, whatever
    | this list says.
    |
    */

    'except' => [
        'horizon',
        'horizon/*',
        'telescope',
        'telescope/*',
        'pulse',
        'pulse/*',
        'nova',
        'nova/*',
        'nova-api/*',
        'livewire/*',
        // Livewire 4 serves its endpoints under livewire-{hash}/.
        'livewire-*',
        'broadcasting/auth',
        'sanctum/csrf-cookie',
        'up',
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | The dashboard and its JSON API are served at "path", on "domain" when it
    | is set, behind "middleware" (docs/adr/0015, SPEC-018). Two gates decide
    | who gets in: viewDynamicThrottle to read and manageDynamicThrottle to
    | change. Until your app defines them, only the local environment gets in.
    | No rule ever applies to the dashboard, which protects itself with
    | Laravel's own throttle: "rate_limit" requests a minute per user, or per
    | IP for a guest, and 0 turns it off.
    |
    */

    'dashboard' => [
        'enabled' => env('DYNAMIC_THROTTLE_DASHBOARD', true),
        'path' => env('DYNAMIC_THROTTLE_DASHBOARD_PATH', 'dynamic-throttle'),
        'domain' => env('DYNAMIC_THROTTLE_DASHBOARD_DOMAIN'),
        'middleware' => ['web'],
        'rate_limit' => 120,
    ],

    /*
    |--------------------------------------------------------------------------
    | Counter driver
    |--------------------------------------------------------------------------
    |
    | "auto" uses the Redis driver (one atomic Lua script per request, or per
    | rule on Redis Cluster) when the cache store below uses Laravel's redis
    | driver, and the generic cache driver on any other store. "redis" forces
    | the Redis driver; "cache" forces the cache driver (docs/adr/0005,
    | docs/adr/0026).
    |
    */

    'driver' => env('DYNAMIC_THROTTLE_DRIVER', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | Cache store
    |--------------------------------------------------------------------------
    |
    | The cache store that holds the counters. null means your default cache
    | store. Prefer Redis, Valkey or Memcached. The database store adds
    | database writes to every limited request, and the file and apc stores
    | count per server, so limits multiply by your number of servers.
    |
    */

    'store' => env('DYNAMIC_THROTTLE_STORE'),

    /*
    |--------------------------------------------------------------------------
    | Redis connection
    |--------------------------------------------------------------------------
    |
    | The Redis connection the Redis driver uses. null means the cache store's
    | own connection when it is a redis store, and "default" otherwise. Give
    | the package a connection of its own, with "timeout" and "read_timeout"
    | ("read_write_timeout" with Predis) of about 0.25 seconds: without them,
    | a Redis that hangs holds each request up to PHP's default_socket_timeout,
    | 60 seconds (docs/guide/storage-and-runtimes.md#timeouts).
    |
    */

    'redis_connection' => env('DYNAMIC_THROTTLE_REDIS_CONNECTION'),

    /*
    |--------------------------------------------------------------------------
    | Key prefix
    |--------------------------------------------------------------------------
    |
    | Added to every key the package writes: up to 32 characters from A-Z, a-z,
    | 0-9, ":", "_", "." and "-". Apps that share one Redis must use different
    | prefixes.
    |
    */

    'prefix' => env('DYNAMIC_THROTTLE_PREFIX', 'dt:'),

    /*
    |--------------------------------------------------------------------------
    | Database
    |--------------------------------------------------------------------------
    |
    | The connection for the package's tables, such as the rules. null means
    | your default connection. Requests never query it: rules reach them
    | through a compiled manifest in the cache (docs/adr/0007).
    |
    */

    'database' => [
        'connection' => env('DYNAMIC_THROTTLE_DB_CONNECTION'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Compiled rules (the manifest)
    |--------------------------------------------------------------------------
    |
    | Requests read the rules from a manifest in the cache store above, never
    | from the database (docs/adr/0007). Octane and queue workers also keep
    | it in memory, and on PHP-FPM with the APCu extension, a server's
    | workers share it in APCu. Both check the cache again every
    | refresh_seconds, so a changed rule applies within that time. 0 means
    | every request reads the cache.
    |
    | "schedule" registers "php artisan dynamic-throttle:compile" in your
    | scheduler every five minutes, so the manifest heals itself after the
    | cache is cleared. Your scheduler must be running.
    |
    */

    'manifest' => [
        // Read as written: env() would turn "true" into a boolean, which counts as 1.
        'refresh_seconds' => filter_var(Env::getRepository()->get('DYNAMIC_THROTTLE_MANIFEST_REFRESH') ?? 2, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'default' => 2],
        ]),
        'schedule' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Matching
    |--------------------------------------------------------------------------
    |
    | Every rule that matches a request counts it, in priority order, until
    | one rejects it (docs/adr/0008). Only the first max_rules_per_request
    | matches apply, and the rest are ignored. Each one that applies costs a
    | trip to the cache store.
    |
    */

    'matching' => [
        // Read as written, like refresh_seconds above.
        'max_rules_per_request' => filter_var(Env::getRepository()->get('DYNAMIC_THROTTLE_MAX_RULES') ?? 5, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'default' => 5],
        ]),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scopes
    |--------------------------------------------------------------------------
    |
    | A rule's scope decides who a request counts for: its IP, user, API token
    | or a header, everyone at once, or a scope your app registers, such as a
    | tenant (docs/adr/0009):
    |
    |     DynamicThrottle::scope('tenant', fn (Request $request) => ...);
    |
    | "ipv6_prefix" groups IPv6 clients, because one client usually controls
    | a whole /64 and could otherwise rotate addresses for fresh counters.
    | 128 counts every address on its own.
    |
    | "guards" is null to count the user the request authenticated as, which
    | never costs a lookup. A list such as ['sanctum', 'web'] asks each guard
    | in turn, and pays that guard's own lookup, such as a token query.
    |
    */

    'scopes' => [
        'ip' => [
            // Read as written, like refresh_seconds above.
            'ipv6_prefix' => filter_var(Env::getRepository()->get('DYNAMIC_THROTTLE_IPV6_PREFIX') ?? 64, FILTER_VALIDATE_INT, [
                'options' => ['min_range' => 1, 'max_range' => 128, 'default' => 64],
            ]),
        ],
        'user' => [
            'guards' => null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Telemetry
    |--------------------------------------------------------------------------
    |
    | After each response, the middleware counts what each rule did with the
    | request: hits, blocks and would-be blocks per minute, in the counter
    | store. On Redis it also keeps each rule's top offenders and how many
    | different clients it blocked, per hour (docs/adr/0013). Recording
    | never slows the response, and never breaks it.
    |
    | "sample_rate", from 0 to 1, records that share of requests, each
    | weighed so the counts stay right on average. Lower it if recording
    | costs too much on a busy app.
    |
    | "schedule" registers "php artisan dynamic-throttle:flush-metrics" in
    | your scheduler every minute, which moves the numbers to the database,
    | and "php artisan dynamic-throttle:prune" daily, which deletes those
    | older than "retention_hours". Your scheduler must be running: the
    | counter store keeps the numbers for 2 hours only.
    |
    | "live_feed" keeps each block and would-be block of the last hour on
    | Redis, with the client's raw IP and user, for the dashboard's
    | Activity page (docs/adr/0024). Turn it off if your app must not keep
    | raw IPs, even for an hour.
    |
    */

    'telemetry' => [
        'enabled' => filter_var(env('DYNAMIC_THROTTLE_TELEMETRY', true), FILTER_VALIDATE_BOOL),
        // Read as written, like refresh_seconds above.
        'sample_rate' => filter_var(Env::getRepository()->get('DYNAMIC_THROTTLE_SAMPLE_RATE') ?? 1, FILTER_VALIDATE_FLOAT, [
            'options' => ['min_range' => 0, 'max_range' => 1, 'default' => 1.0],
        ]),
        'schedule' => true,
        'retention_hours' => 720,
        'live_feed' => filter_var(env('DYNAMIC_THROTTLE_LIVE_FEED', true), FILTER_VALIDATE_BOOL),
    ],

    /*
    |--------------------------------------------------------------------------
    | Failures
    |--------------------------------------------------------------------------
    |
    | When the counter store fails, each rule's on_failure decides: "open"
    | lets the request through, and "closed" answers 503 (docs/adr/0010).
    | The package then skips the store for cooldown_seconds, so an outage
    | costs one failed call per cooldown instead of one per request, and
    | reports each cause of a failure at most once per cooldown. 0 turns
    | the cooldown off.
    |
    | The cooldown lives in the process, for Octane and queue workers, and
    | in APCu when the extension is enabled, where a server's PHP-FPM
    | workers share it. Without APCu, PHP-FPM tries the store on every
    | request, and reports every failure.
    |
    */

    'failure' => [
        // Read as written, like refresh_seconds above.
        'cooldown_seconds' => filter_var(Env::getRepository()->get('DYNAMIC_THROTTLE_COOLDOWN') ?? 10, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 0, 'default' => 10],
        ]),
    ],

];
