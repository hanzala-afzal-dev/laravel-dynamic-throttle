<?php

declare(strict_types=1);

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
    | Counter driver
    |--------------------------------------------------------------------------
    |
    | "auto" uses the Redis driver (one atomic Lua script per rule) when the
    | cache store below uses Laravel's redis driver, and the generic cache
    | driver on any other store. "redis" forces the Redis driver; "cache"
    | forces the cache driver (docs/adr/0005).
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
    | own connection when it is a redis store, and "default" otherwise. A
    | dedicated connection with short timeouts is recommended (docs/adr/0010).
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

];
