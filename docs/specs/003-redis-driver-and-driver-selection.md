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
- The script runs with `EVALSHA`, and falls back to `EVAL` on `NOSCRIPT` (after a restart or failover that flushed the script cache). It uses the raw phpredis or predis client, because Laravel's phpredis `evalsha()` sends `SCRIPT LOAD` on every call.
- The Redis connection is resolved on the first attempt, not in the constructor, so resolving the store never touches the network (ADR-0005).
- Any Redis error becomes `StorageUnavailableException`.
- **Driver selection** (config `dynamic-throttle.driver`):
  - `auto` (default): the Redis driver when the selected cache store uses the `redis` driver, otherwise the cache driver on that store.
  - `redis`: the Redis driver on `dynamic-throttle.redis_connection`. When that is null: the cache store's connection if it is a redis store, else `default`.
  - `cache`: the cache driver, even on a Redis store (ADR-0005's fallback when Lua is unavailable).
  - `dynamic-throttle.store` names the cache store; null means the default store. `dynamic-throttle.prefix` is the key prefix (default `dt:`).
  - The service provider binds `LimiterStore` (scoped, so Octane resolves it per request), `KeyBuilder` and `Clock`.

## Acceptance criteria
Window 60 s and max 5 unless stated. Times are offsets from T0 = 1,800,000,000 s.

- **AC-1 (contract)** The Redis driver passes the whole SPEC-002 contract suite on Redis and Valkey, with both phpredis and predis.
- **AC-2** After three attempts at T0+10, the key `{prefix}c:1:60:{hash}` is a hash whose field `30000000` holds 3, and whose TTL is 124 or 125 s.
- **AC-3** Old windows are removed: after one attempt each at T0+10, T0+70 and T0+130, the hash holds only the fields `30000001` and `30000002`.
- **AC-4** Rejected attempts write nothing: after five allowed and three rejected attempts at T0+10, the field holds 5.
- **AC-5** Without a test clock, the time comes from Redis `TIME`: an attempt's window index equals floor(TIME / 60), with TIME read just before or just after the attempt.
- **AC-6** After `SCRIPT FLUSH`, an attempt still succeeds, and afterwards `SCRIPT EXISTS` reports the script as loaded. The next attempt runs through `EVALSHA`: INFO commandstats shows `evalsha` calls up by 1 and `eval` calls unchanged.
- **AC-7** A connection to a closed port makes `attempt()` throw `StorageUnavailableException`.
- **AC-8 (model-based)** 2,000 seeded random steps over 3 identities:
  - windows 3, 7 and 60 s; max 1 to 10. Not 1 s: Redis expires keys in real time after 2 × window + 5 s, and a 7 s lifetime could run out on a slow CI runner while the in-memory reference keeps the counts.
  - the clock advances 0 to 130 s in microseconds between steps
  - each attempt from the Redis driver equals the cache driver's on the array store with the same clock: allowed, remaining, retryAfter and window index
  - no aligned window of any counter allows more than its max
- **AC-9 (concurrency)** 50 processes make 20 attempts each on one counter, with max 100 and the time frozen at T0+10. **Exactly** 100 are allowed, and the field holds 100 (ADR-0016).
- **AC-10 (selection)** With `driver = auto`:
  - a default cache store with the `redis` driver resolves `RedisLimiterStore` on that store's connection
  - `array`, `file`, `database` or `memcached` stores resolve `CacheLimiterStore` on that store
- **AC-11 (selection)** `driver = cache` on a redis store resolves `CacheLimiterStore`. `driver = redis` resolves `RedisLimiterStore` on:
  - `redis_connection` when it is set
  - otherwise the store's own connection, if the store is a redis store
  - otherwise `default`
- **AC-12 (selection)** `store = 'file'` makes `auto` use the file store instead of the default store.
- **AC-13 (selection)** With Redis pointed at a closed port, resolving `LimiterStore` from the container succeeds without a network call. The first `attempt()` throws `StorageUnavailableException`.
- **AC-14 (selection)** An unknown `driver` value throws `InvalidArgumentException` naming `auto`, `redis` and `cache` when `LimiterStore` is resolved.
- **AC-15 (keys)** `KeyBuilder::counterBase()` gives `dt:c:42:60:{hash}`, and `counter()` is `counterBase()` + `:` + window index. The container's `KeyBuilder` uses `dynamic-throttle.prefix`.

## Interfaces and files
- `src/Engine/Stores/RedisLimiterStore.php`: `__construct(RedisFactory $redis, string $connection, KeyBuilder $keys, ?Clock $testClock = null)`.
- `src/Engine/KeyBuilder.php`: adds `counterBase()`.
- `src/Support/LimiterStoreFactory.php`: reads the config and builds the driver.
- `config/dynamic-throttle.php`: `driver`, `store`, `redis_connection`, `prefix`.
- `DynamicThrottleServiceProvider`: bindings for `LimiterStore`, `KeyBuilder` and `Clock`.
- Tests:
  - `tests/Contract/LimiterStoreContractTest.php` (Redis driver rows)
  - `tests/Drivers/RedisLimiterStoreTest.php`, `tests/Drivers/RedisModelTest.php`, `tests/Drivers/RedisConcurrencyTest.php`
  - `tests/Feature/LimiterStoreFactoryTest.php`

## Out of scope
- `release()` and compensation: SPEC-004.
- Running tests on a Redis Cluster; the single-key design is cluster-safe by construction.
- Health warnings about store types: SPEC-016.
- What the middleware does on `StorageUnavailableException`: SPEC-012.

## Edge cases and failure modes
- **Floating-point parity.** Lua numbers are IEEE doubles like PHP floats. The script does the same operations in the same order as SPEC-001, and AC-8 checks the results are identical.
- **Integer return values.** Redis turns Lua numbers into integer replies, so the script returns only integers: the allowed flag, remaining, retryAfter and the window index.
- **Window index at a boundary.** A floor division in doubles is corrected by one step if needed, so the index is exact.
- **Replication.** `TIME` followed by writes is safe from Redis 5 on, where scripts replicate their effects; ADR-0002 requires 6.2+.
- **Key prefixes.** Laravel's Redis `options.prefix` is applied to the script's KEYS by both clients.

## End-to-end verification
- `composer test`, `composer analyse`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate` ≥ 85%

## Open questions
None. Drafted from the ADRs, open to change at review.
