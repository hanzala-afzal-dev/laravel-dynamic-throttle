# SPEC-003: Redis driver and driver selection

- Status: In progress
- ADRs: ADR-0005, ADR-0006, ADR-0007, ADR-0016
- Roadmap phase: 1

## Problem
The cache driver (SPEC-002) works everywhere, but it needs 3–4 round trips per rule and can reject a little early under concurrency. On Redis and Valkey, a Lua script can do the whole decision atomically, in one round trip, with a clock every app server agrees on (ADR-0005, ADR-0006). Apps also need the right driver picked from config, without network calls at boot.

## Behavior
- `RedisLimiterStore` keeps **one hash per counter**: key `{prefix}c:{ruleId}:{windowSeconds}:{identityHash}`, one field per window index. A single key is safe on Redis Cluster, where a script may only touch keys in one slot.
- One Lua script does everything atomically. It reads the time with `TIME`, reads the current and previous windows, and applies SPEC-001's formula, ported operation for operation so the floating-point results are identical. When allowed, it counts the request, deletes fields older than the previous window, and sets the TTL to `2 × window + 5`. A rejected request writes nothing.
- An optional test clock replaces `TIME` with a time argument. It is used only by tests (ADR-0006).
- The script runs with `EVALSHA`, and falls back to `EVAL` on `NOSCRIPT` (after a restart or failover that flushed the script cache). Both commands go through the connection's `command()`, not Laravel's phpredis `evalsha()`, which sends `SCRIPT LOAD` on every call. `command()` also rebuilds a phpredis client after a lost connection. A raw phpredis client never recovers from an outage, which would leave a long-lived worker without limits (AC-16).
- The Redis connection is resolved on the first attempt, not in the constructor, so resolving the store never touches the network (ADR-0005).
- Any Redis error becomes `StorageUnavailableException`.
- **Driver selection** (config `dynamic-throttle.driver`):
  - `auto` (default): the Redis driver when the selected cache store uses the `redis` driver, otherwise the cache driver on that store. The Redis driver runs on the same connection as with `redis`.
  - `redis`: the Redis driver on `dynamic-throttle.redis_connection`. When that is null: the cache store's connection if it is a redis store, else `default`.
  - `cache`: the cache driver, even on a Redis store (ADR-0005's fallback when Lua is unavailable).
  - `dynamic-throttle.store` names the cache store; null means the default store. `dynamic-throttle.prefix` is the key prefix (default `dt:`).
  - The service provider binds `LimiterStore` (scoped, so Octane resolves it per request), `KeyBuilder` and `Clock`.

## Acceptance criteria
Window 60 s and max 5 unless stated. Times are offsets from T0 = 1,800,000,000 s.

- **AC-1 (contract)** The Redis driver passes the whole SPEC-002 contract suite on Redis and Valkey, with both phpredis and predis.
- **AC-2** After three attempts at T0+10, the key `{prefix}c:1:60:{hash}` is a hash whose field `30000000` holds 3. Its remaining lifetime (`PTTL`) is above 124,000 ms and at most 125,000 ms, so a TTL of 2 × window + 4 fails.
- **AC-3** Old windows are removed: after one attempt each at T0+10, T0+70 and T0+130, the hash holds only the fields `30000001` and `30000002`.
- **AC-4** Rejected attempts write nothing: after five allowed and three rejected attempts at T0+10, the field holds 5.
- **AC-5** Without a test clock, the time comes from Redis `TIME`: an attempt's window index equals floor(TIME / 60), with TIME read just before or just after the attempt. The container builds the Redis driver without a clock: with Laravel's clock travelled to 2030, the window index still follows `TIME`.
- **AC-6** After `SCRIPT FLUSH`, an attempt still succeeds, and afterwards `SCRIPT EXISTS` reports the script as loaded. A connection whose ACL user may not run `EVAL` can attempt next, which only works through `EVALSHA`. Under `--parallel`, another test process can reload the script between the flush and the attempt, so failure-injection tests with stubbed clients check the fallback too:
  - `NOSCRIPT` from phpredis (`false` and the last error), from predis (a `ServerException`) and from predis with exceptions off (an error reply) each lead to exactly one `EVAL`, and the attempt succeeds.
  - Any other error leads to no `EVAL`, and to `StorageUnavailableException` naming it.

  Changed in review: this criterion first counted calls with INFO commandstats, which CLAUDE.md forbids, because other test processes change server-wide statistics.
- **AC-7** A connection to a closed port makes `attempt()` throw `StorageUnavailableException`.
- **AC-8 (model-based)** 2,000 seeded random steps over 3 identities:
  - windows 3, 7 and 60 s; max 1 to 10. Not 1 s: Redis expires keys in real time after 2 × window + 5 s, and a 7 s lifetime could run out on a slow CI runner while the in-memory reference keeps the counts.
  - the clock advances 0 to 130 s in microseconds between steps
  - each attempt from the Redis driver equals the cache driver's on the array store with the same clock: allowed, remaining, retryAfter and window index
  - no aligned window of any counter allows more than its max
- **AC-9 (concurrency)** 50 processes make 20 attempts each on one counter, with max 100 and the time frozen at T0+10. **Exactly** 100 are allowed, and the field holds 100 (ADR-0016).
- **AC-10 (selection)** With `driver = auto`:
  - a default cache store with the `redis` driver resolves `RedisLimiterStore` on `redis_connection` when it is set, and otherwise on that store's connection
  - `array`, `file`, `database` or `memcached` stores resolve `CacheLimiterStore` on that store

  Changed in review: `auto` ignored `redis_connection`, although the config file documents it as the Redis driver's connection and ADR-0010 recommends a dedicated connection with short timeouts.
- **AC-11 (selection)** `driver = cache` on a redis store resolves `CacheLimiterStore`. `driver = redis` resolves `RedisLimiterStore` on:
  - `redis_connection` when it is set
  - otherwise the store's own connection, if the store is a redis store
  - otherwise `default`
- **AC-12 (selection)** `store = 'file'` makes `auto` use the file store instead of the default store.
- **AC-13 (selection)** With Redis pointed at a closed port, resolving `LimiterStore` from the container succeeds without a network call. The first `attempt()` throws `StorageUnavailableException`. Both clients are tested: predis connects lazily anyway, so only phpredis proves that resolving opens no connection.
- **AC-14 (selection)** An unknown `driver` value throws `InvalidArgumentException` naming `auto`, `redis` and `cache` when `LimiterStore` is resolved.
- **AC-15 (keys)** `KeyBuilder::counterBase()` gives `dt:c:42:60:{hash}`, and `counter()` is `counterBase()` + `:` + window index. The container's `KeyBuilder` uses `dynamic-throttle.prefix`.

Added in review:

- **AC-16 (outage)** A long-lived process (an Octane worker) works again after Redis was unreachable. Redis is reached through a TCP proxy. With the proxy stopped, an attempt throws `StorageUnavailableException`. Once it is back, one of the next two attempts is allowed, with phpredis and with predis. Laravel rebuilds a phpredis client on the command after a lost connection, so the first of the two may still fail.
- **AC-17 (error replies)** When the counter's key holds a string, `attempt()` throws `StorageUnavailableException`, and Redis's `WRONGTYPE` error appears in its message or its previous exceptions. This holds with phpredis, with predis, and with predis with exceptions off.
- **AC-18 (malformed replies)** A reply that is not a list of four integers makes `attempt()` throw `StorageUnavailableException`, never a `TypeError`. Tested with stubbed clients answering `[1, 4, 0]`, `[1, '4', 0, 30000000]` and `'OK'`. So does a connection that is neither phpredis nor predis, with an `InvalidArgumentException` naming it as the previous exception.
- **AC-19 (Laravel key prefix)** With Laravel's Redis option `prefix` set to `app:`, the counter hash lives at `app:{prefix}c:1:60:{hash}`, and nothing is written to the key without `app:`. Both clients apply the option to the script's KEYS.
- **AC-20 (large limits, contract)** The first attempt with max 2^53 + 3 is allowed with remaining 2^53 + 2, and with max `PHP_INT_MAX` with remaining `PHP_INT_MAX` − 1. A Lua number is a double, exact only up to 2^53, so the script returns the rounded-up estimate and PHP subtracts it from max in integers. Before the review, the Redis driver answered 2^53 + 4 and -2^63.

## Interfaces and files
- `src/Engine/Stores/RedisLimiterStore.php`: `__construct(RedisFactory $redis, string $connection, KeyBuilder $keys, ?Clock $testClock = null)`.
- `src/Engine/KeyBuilder.php`: adds `counterBase()`.
- `src/Support/LimiterStoreFactory.php`: reads the config and builds the driver.
- `config/dynamic-throttle.php`: `driver`, `store`, `redis_connection`, `prefix`.
- `DynamicThrottleServiceProvider`: bindings for `LimiterStore`, `KeyBuilder` and `Clock`.
- Tests:
  - `tests/Contract/LimiterStoreContractTest.php` (Redis driver rows)
  - `tests/Drivers/RedisLimiterStoreTest.php`, `tests/Drivers/RedisModelTest.php`, `tests/Drivers/RedisConcurrencyTest.php`
  - `tests/Unit/Engine/Stores/RedisLimiterStoreRepliesTest.php`: failure injection with stubbed clients (AC-6, AC-18)
  - `tests/Support/tcp-proxy.php`: a proxy to stop and restart for AC-16
  - `tests/Feature/LimiterStoreFactoryTest.php`

## Out of scope
- `release()` and compensation: SPEC-004.
- Running tests on a Redis Cluster; the single-key design is cluster-safe by construction.
- Health warnings about store types: SPEC-016.
- What the middleware does on `StorageUnavailableException`: SPEC-012.

## Edge cases and failure modes
- **Floating-point parity.** Lua numbers are IEEE doubles like PHP floats. The script does the same operations in the same order as SPEC-001, and AC-8 checks the results are identical.
- **Integer return values.** Redis turns Lua numbers into integer replies, so the script returns only integers: the allowed flag, the rounded-up estimate (0 when rejected), retryAfter and the window index. PHP computes remaining from the estimate, so it stays exact above 2^53 (AC-20). Allow/reject decisions compare in doubles in both drivers.
- **Retried commands.** Laravel 13 retries a command after a lost connection only when the connection sets `command_retries`. If the first run of the script had already reached Redis, that request is counted twice: stricter, never looser. The limiter's dedicated connection should not set `command_retries`.
- **Window index at a boundary.** A floor division in doubles is corrected by one step if needed, so the index is exact.
- **Replication.** `TIME` followed by writes is safe from Redis 5 on, where scripts replicate their effects; ADR-0002 requires 6.2+.
- **Key prefixes.** Laravel's Redis `options.prefix` is applied to the script's KEYS by both clients.

## End-to-end verification
- `composer test`, `composer analyse`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate` ≥ 85%

## Open questions
None. Drafted from the ADRs, open to change at review.
