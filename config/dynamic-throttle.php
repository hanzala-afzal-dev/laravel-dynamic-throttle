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

];
