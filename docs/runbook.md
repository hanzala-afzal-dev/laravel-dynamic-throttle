# Runbook

What to do when rate limiting goes wrong in production. Each scenario has four parts:

- **Symptoms:** what you see, in your app, your error tracker and the package's commands.
- **Check:** the commands that confirm it, and what they print.
- **Act:** the steps, in order.
- **After:** what comes back by itself, and what to check once it is over.

Each scenario links the test that follows its steps, so the steps stay true as the package changes.

| Scenario | In short |
|---|---|
| [Redis is down](#redis-is-down) | Limits are off, fail-closed routes answer 503, and both recover by themselves. |

## Before you need it

- **On PHP-FPM, enable APCu.** A server then keeps the rules it last read through an outage, and its workers share the cooldown and the error reports ([storage and runtimes](guide/storage-and-runtimes.md)).
- **Give the counters a Redis connection of their own, with timeouts of a few hundred milliseconds** (`DYNAMIC_THROTTLE_REDIS_CONNECTION`). A Redis that hangs, instead of refusing, holds the first request of each cooldown for as long as the connection waits, and every request on PHP-FPM without APCu ([ADR-0010](adr/0010-failure-policy.md)).
- **Alert on `StorageUnavailable`** ([events](guide/events.md)). During an outage it is dispatched about once per cooldown, not once per request.
- **In production, the commands that loosen limits ask first.** The steps below pass `--force`, for a terminal that cannot answer.

## Redis is down

The same steps hold for Valkey, and for any store that holds your counters, such as Memcached.

Tested by [RedisDownTest](../tests/Feature/Runbook/RedisDownTest.php).

### Symptoms

- **Nothing is limited** on routes whose rules fail open, the default `on_failure`. Their responses carry no `X-RateLimit-*` headers.
- **Routes with a fail-closed rule answer 503**, with `Retry-After` set to what is left of the cooldown.
- **Your error tracker** shows `StorageUnavailableException`: "The Redis limiter store could not count the request." ("The limiter store could not count the request." with the cache driver), with Redis's own error as the previous exception. It is reported at most once per `failure.cooldown_seconds` (10 by default), per process, or per server on PHP-FPM with APCu.
- **When the manifest is in that Redis too,** the usual setup, it also shows `ManifestUnavailable`: "The manifest could not be read: …", at most once per 30 seconds, per process, or per server with APCu.
- **Your `StorageUnavailable` listeners** run, about once per cooldown.

Without APCu, each PHP-FPM request tries Redis again, and reports again.

### Check

```bash
php artisan dynamic-throttle:status
```

It still exits 0, and prints the driver, such as `redis, connection default`. When the manifest is in that Redis too, it prints `cannot be read:` and Redis's error on the `Manifest` line. Then check Redis itself, with your provider's console or `redis-cli -h <host> ping`.

### Act

1. **Leave the fail-open routes alone.** They serve every request, unlimited, until Redis is back. That is what failing open is for ([when something fails](guide/failures.md)).
2. **Decide about the fail-closed routes.** They answer 503 until Redis is back, which is what you chose them for, such as a login route under attack. To let their requests through meanwhile:
   - **If the manifest is in another store that still works,** such as when the counters have a Redis connection of their own, turn bypass on. Every request goes through, unlimited, and bypass ends by itself:

     ```bash
     php artisan dynamic-throttle:bypass on --for=30m --force
     ```

   - **If the manifest is in that Redis too,** bypass cannot reach your servers: it is saved, but the manifest cannot be written, and the command exits 1. Use the kill switch instead: set `DYNAMIC_THROTTLE_ENABLED=false`, then reload the config (`php artisan config:cache`, then reload PHP-FPM, or run `php artisan octane:reload`). The middleware then does nothing.
3. **Know what a restart does.** A process keeps the rules it read before the outage, and on PHP-FPM with APCu, so does the server. A process that starts during the outage, on a server that kept none, applies no rule at all, so its fail-closed routes let requests through too ([ADR-0010](adr/0010-failure-policy.md) point 3).
4. **Bring Redis back,** or fail over to a replica.

### After

- **Counting resumes by itself.** Each process, or each server with APCu, tries Redis again when its cooldown ends, and counts from then on. Nothing needs to run.
- **If Redis kept its data,** the counts from before the outage still count, and servers read the manifest again within `manifest.refresh_seconds`.
- **If Redis came back empty,** every client starts a fresh window, and the manifest is gone. The first request after it rebuilds it once its response is sent, or run `php artisan dynamic-throttle:compile`.
- **Undo what you changed:** `php artisan dynamic-throttle:bypass off`, or set `DYNAMIC_THROTTLE_ENABLED` back and reload the config. A bypass that was saved while the manifest could not be written applies at the next compile, such as the scheduled one, until its end time, so turn it off if you no longer want it.
- **Check:** `php artisan dynamic-throttle:status` shows the manifest's version, and `Bypass` off.
