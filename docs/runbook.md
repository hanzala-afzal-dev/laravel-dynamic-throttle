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
| [The manifest was evicted](#the-manifest-was-evicted) | Usually heals itself after one request. Run `dynamic-throttle:compile` if it does not. |
| [A bad rule blocks /api](#a-bad-rule-blocks-api) | Find it with `explain`, switch it off with `disable-rule`, or bypass every rule. |
| [An emergency change was left on](#an-emergency-change-was-left-on) | `health --strict` warns about it. End it with `bypass off` or `scale off`. |

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
- **If Redis came back empty,** every client starts a fresh window, and the manifest is gone: see [the manifest was evicted](#the-manifest-was-evicted).
- **Undo what you changed:** `php artisan dynamic-throttle:bypass off`, or set `DYNAMIC_THROTTLE_ENABLED` back and reload the config. A bypass that was saved while the manifest could not be written applies at the next compile, such as the scheduled one, until its end time, so turn it off if you no longer want it.
- **Check:** `php artisan dynamic-throttle:status` shows the manifest's version, and `Bypass` off.

## The manifest was evicted

The manifest is the compiled copy of your rules that requests read from the cache. The cache can lose it: Redis evicts it when its memory is full, restarts without persistence, or `php artisan cache:clear` empties it.

Tested by [ManifestEvictedTest](../tests/Feature/Runbook/ManifestEvictedTest.php).

### Symptoms

- **Usually, one request without limits, then nothing.** A process that had read the manifest keeps applying its rules. One that had not applies no rule to its first request, and that request rebuilds the manifest from the database once its response is sent. The next request is limited again.
- **Your error tracker** shows `ManifestUnavailable`: "The manifest is missing from the cache. One request will rebuild it after its response." It is reported at most once per 30 seconds, per process, or per server on PHP-FPM with APCu.
- **When the rebuild fails,** such as when the database is down, it also shows `ManifestCompileFailed`: "The manifest could not be compiled: …". Processes that had not read the manifest then apply no rule until a compile succeeds. One request, across your servers, tries the rebuild again every 30 seconds.

### Check

```bash
php artisan dynamic-throttle:status
php artisan dynamic-throttle:health
```

Until the manifest is rebuilt, `status` prints `missing: run php artisan dynamic-throttle:compile` on the `Manifest` line, and exits 0. `health` errs on `manifest`, "The manifest is missing, so no rule applies", and exits 1.

### Act

1. **Rebuild it now:**

   ```bash
   php artisan dynamic-throttle:compile
   ```

   It prints `Compiled manifest version …`. Servers read it on their next request, or within `manifest.refresh_seconds` for those that kept the previous one.
2. **If it prints `Could not compile the manifest: …`,** fix what it names, usually the database connection, and run it again.
3. **If the cache keeps losing it,** remove the cause:
   - **Redis is full and evicts keys.** Give it more memory, or give the counters a Redis of their own (`DYNAMIC_THROTTLE_REDIS_CONNECTION`), so they cannot crowd the manifest out. The manifest has no expiry and the counters do, so a `volatile-*` eviction policy never evicts the manifest.
   - **`php artisan cache:clear`** empties the cache store, the manifest with it. Run `php artisan dynamic-throttle:compile` right after it, such as in your deploy script.

### After

- **Check:** `php artisan dynamic-throttle:health` shows `manifest` ok, with the new version.
- **Nothing to undo:** a compile changes no rule.
- **The scheduler heals it too.** With `manifest.schedule` on, the default, the scheduler runs `dynamic-throttle:compile` every five minutes, so a manifest lost while nobody watches comes back by itself, as long as `php artisan schedule:run` runs every minute.

## A bad rule blocks /api

A rule answers 429 to far more requests than it should: a limit set too low, a `global` scope where each client needed a counter of its own, or an `ip` rule that counts your load balancer's IP for every client because the trusted proxies are not set.

Tested by [BadRuleTest](../tests/Feature/Runbook/BadRuleTest.php).

### Symptoms

- **Clients get 429** on routes under `/api`, many of them at once, with `Retry-After` and `X-RateLimit-Remaining: 0`.
- **Your `RateLimitExceeded` listeners** run once for each 429, with the rule's `ruleId` and `ruleName`, and the request's `path` ([events](guide/events.md)).

### Check

```bash
php artisan dynamic-throttle:explain GET /api/orders
```

It shows each rule that matches the request, and ends with the decision, such as `Decision: rejected by rule 12: 429, Retry-After 120 s`. The rule's lines show its limit and its `Scope`. Add `--ip=`, `--user=` or `--header=` to explain the request of a given client ([why a request is limited](guide/explain.md)).

When the rule is an `ip` rule and every client seems to share one counter, check the trusted proxies: on Redis, `php artisan dynamic-throttle:health` warns on `dominant-ip` when one IP sends half or more of the recent traffic ([health checks](guide/health.md)).

### Act

1. **Find the rule's id**, if `explain` did not show it:

   ```bash
   php artisan dynamic-throttle:list
   ```

2. **Switch it off.** The requests after it are no longer counted by it. In production, the command asks first, because the rule's protection goes with it; `--force` skips the question.

   ```bash
   php artisan dynamic-throttle:disable-rule 12 --force
   ```

3. **If you cannot find the rule quickly,** bypass every rule instead. Every request goes through, unlimited, until bypass ends by itself. In production it asks first too, since it removes every limit.

   ```bash
   php artisan dynamic-throttle:bypass on --for=30m --force
   ```

4. **Fix the rule** with the `Rule` model, such as in `php artisan tinker`:

   ```php
   use Afzal\DynamicThrottle\Enums\Scope;
   use Afzal\DynamicThrottle\Models\Rule;

   Rule::find(12)->update(['scope' => Scope::Ip, 'max_attempts' => 60]);
   ```

5. **Bring it back:**

   ```bash
   php artisan dynamic-throttle:enable-rule 12
   ```

### After

- **Check:** `dynamic-throttle:explain GET /api/orders` shows the fixed rule and `Decision: allowed`. If you bypassed, `php artisan dynamic-throttle:status` shows its end time; end it early with `php artisan dynamic-throttle:bypass off`.
- **The audit log** has one entry for each command that changed something, `rule.disable`, `rule.enable` or `settings.bypass`, with the system user who ran it ([during an incident](guide/incidents.md)).

## An emergency change was left on

Someone turned bypass or a scale factor on during an incident, and nobody turned it off. Bypass lifts every limit. A factor below 1 tightens every limit, and one above 1 loosens them.

Tested by [EmergencyLeftOnTest](../tests/Feature/Runbook/EmergencyLeftOnTest.php), and the `emergency` check by [HealthEmergencyTest](../tests/Feature/Console/HealthEmergencyTest.php).

### Symptoms

- **Nothing is limited** while bypass is on. Responses carry no `X-RateLimit-*` headers.
- **`dynamic-throttle:health --strict` fails** in your deploy script, on `emergency`, such as "Bypass applies with no end time: end it with php artisan dynamic-throttle:bypass off once the emergency is over."

### Check

```bash
php artisan dynamic-throttle:status
php artisan dynamic-throttle:health
```

`status` shows each setting with its end time: `on until 2027-01-15 08:30:00 UTC, 30 minutes left`, `ended at …` once it has ended, or `on, with no end time` for `--forever`. The `emergency` check warns about a setting with no end time, or one more than 24 hours away. One that ends within 24 hours is ok, with its end time, so a deploy in the middle of an incident still passes `--strict`.

### Act

1. **A setting with an end time needs nothing.** Without `--for`, bypass and scaling end after one hour, by each server's clock, with no command and no compile.
2. **End it now:**

   ```bash
   php artisan dynamic-throttle:bypass off
   php artisan dynamic-throttle:scale off
   ```

   Servers apply it within `manifest.refresh_seconds`, and count requests again.
3. **If you still need it,** give it an end time instead of `--forever`, so that it cannot be forgotten. A day or less keeps `health --strict` passing:

   ```bash
   php artisan dynamic-throttle:bypass on --for=1d --force
   ```

### After

- **Check:** `status` shows `Bypass` and `Scale` off, and `php artisan dynamic-throttle:health --strict` passes `emergency`.
- **The audit log** says who turned it on, and when: a `settings.bypass` or `settings.scale` entry with the system user who ran the command ([during an incident](guide/incidents.md)).
- **To catch it sooner next time,** run `php artisan dynamic-throttle:health --strict` in your deploy script, or on a schedule.
