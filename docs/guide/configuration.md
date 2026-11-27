# Configuration

The defaults suit most apps. The settings you are most likely to change have environment variables:

| Variable | Default | |
|---|---|---|
| `DYNAMIC_THROTTLE_ENABLED` | `true` | The kill switch |
| `DYNAMIC_THROTTLE_STORE` | your default cache store | The cache store that holds the counters |
| `DYNAMIC_THROTTLE_DRIVER` | `auto` | `redis` or `cache`. `auto` picks Redis on a Redis store |
| `DYNAMIC_THROTTLE_REDIS_CONNECTION` | the store's connection | A dedicated connection with short [timeouts](storage-and-runtimes.md#timeouts) is best |
| `DYNAMIC_THROTTLE_PREFIX` | `dt:` | Apps that share one Redis need different prefixes |
| `DYNAMIC_THROTTLE_DB_CONNECTION` | your default connection | Where the package's tables live |
| `DYNAMIC_THROTTLE_MANIFEST_REFRESH` | `2` | Seconds before a server picks up a rule change |
| `DYNAMIC_THROTTLE_MAX_RULES` | `5` | Enforcing rules that may count one request |
| `DYNAMIC_THROTTLE_COOLDOWN` | `10` | Seconds to skip a counter store that failed |
| `DYNAMIC_THROTTLE_TELEMETRY` | `true` | Records each rule's traffic and blocks |
| `DYNAMIC_THROTTLE_SAMPLE_RATE` | `1` | The share of requests telemetry records |
| `DYNAMIC_THROTTLE_LIVE_FEED` | `true` | Keeps the last hour's blocks, with the client's IP, for the dashboard's Activity page |
| `DYNAMIC_THROTTLE_IPV6_PREFIX` | `64` | The size of the IPv6 network that counts as one client, from 1 to 128 ([scopes](rules.md#scopes)) |
| `DYNAMIC_THROTTLE_DASHBOARD` | `true` | Serves the dashboard |
| `DYNAMIC_THROTTLE_DASHBOARD_PATH` | `dynamic-throttle` | The dashboard's path |
| `DYNAMIC_THROTTLE_DASHBOARD_DOMAIN` | none | A domain of its own for the dashboard |
| `DYNAMIC_THROTTLE_DASHBOARD_LINKS` | `false` | One-time links that open the dashboard ([links](dashboard.md#links)) |

The rest, such as excluded paths and the user scope's guards, is in [`config/dynamic-throttle.php`](../../config/dynamic-throttle.php). To change it, publish the file:

```bash
php artisan vendor:publish --tag=dynamic-throttle-config
```
