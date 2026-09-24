# SPEC-004: Compensation (`release`)

- Status: In progress
- ADRs: ADR-0005, ADR-0006, ADR-0008, ADR-0010, ADR-0016
- Roadmap phase: 1

## Problem
When several rules match one request, they are evaluated in priority order, and each counts the request before the next is checked (ADR-0008). If a later enforcing rule rejects it, the earlier rules have already counted a request that never ran. Without compensation, a strict rule's rejections drain every lenient rule matched before it. ADR-0006 says a rejected request is counted by no rule, so the stores need a way to uncount it.

## Behavior
- `LimiterStore::release(Attempt $attempt): void` uncounts an allowed attempt (ADR-0005). The middleware calls it for each enforcing rule that allowed the request before the rule that rejected it (SPEC-007, SPEC-010).
- **Only allowed attempts are released.** A rejected attempt was never counted, so releasing it does nothing and does not touch the store.
- **The attempt's own window.** The count is removed from the window the attempt was counted in (`Attempt::$windowIndex`), even when the window has changed since. Release reads no clock.
- **Never below 0, never a new key.** When the window's count is missing (expired or evicted) or already 0, release does nothing. A count of -1 would hide one allowed request from every later decision: reads treat negative counts as 0, so the next request counted there lands on 0 again.
- **Redis driver:** a second Lua script decrements the window's field when it exists and is above 0. One round trip, with the same `EVALSHA`/`EVAL` fallback as `attempt()` (SPEC-003). It does not change the TTL.
- **Cache driver:** reads the window key and decrements it when it holds a count above 0. Two round trips, without serialization like `attempt()` (SPEC-002 AC-19). The read and the decrement are separate, so two releases racing on a count of 1 could both decrement it. That needs two releases for one counted request, or a count that lost requests to eviction.
- **Storage failures** raise `StorageUnavailableException`, like `attempt()`. The request keeps its rejection and the counter keeps the extra count: stricter, never looser. Callers must not treat this exception as "storage down, allow the request" (SPEC-010, SPEC-012).
- Release each allowed attempt at most once. A second release takes another request's count.

## Acceptance criteria
Window 60 s and max 5 unless stated. Times are offsets from T0 = 1,800,000,000 s. **Contract** criteria run against every store of the SPEC-002 contract suite, with both drivers.

- **AC-1 (contract)** Releasing an allowed attempt gives its place back. After five allowed attempts at T0+10, release one of them. The next attempt is allowed with remaining 0, and the one after is rejected.
- **AC-2 (contract)** Releasing a rejected attempt changes nothing. After five allowed attempts and one rejected attempt at T0+10, release the rejected one: the next attempt is still rejected.
- **AC-3 (contract)** Release uncounts the window the attempt was counted in:
  - Five attempts at T0+59 are allowed (window index 30,000,000).
  - At T0+60 (window index 30,000,001) an attempt is rejected, because the previous window weighs 5 × 60 / 60 = 5.
  - Release one of the T0+59 attempts. At T0+60 an attempt is now allowed with remaining 0 (estimate 4), and the next is rejected.

  A driver that released the current window instead would still reject.
- **AC-4 (contract)** Release never takes a count below 0. With max 2 at T0+10, one attempt is allowed, and releasing it twice leaves the counter empty. Then exactly two attempts are allowed and the third is rejected. A count of -1 would allow three.
- **AC-5 (contract)** Releasing an attempt for a counter that holds nothing changes nothing. Release `new Attempt($key, 2, 60, 30_000_000, true, 1, 0)` on a counter never used. Then, at T0+10 with max 2, exactly two attempts are allowed and the third is rejected.
- **AC-6 (contract)** Release only changes its own counter. After five attempts each on rule "1" and rule "2" (same identity) at T0+10, release one attempt of rule "1". Rule "1" then allows one more attempt, and rule "2" still rejects.
- **AC-7 (no store access)** Releasing a rejected attempt makes no call to the store: `release()` returns normally on a cache store that throws on every call, and on a Redis connection to a closed port.
- **AC-8 (Redis driver)** Release changes the attempt's field by exactly -1 and writes nothing else:
  - After three attempts at T0+10, releasing one leaves field `30000000` at 2, no other field, and a TTL of 124 or 125 s.
  - Releasing an attempt whose field holds 0 leaves the field at 0.
  - Releasing an attempt for a counter never used creates no key.
- **AC-9 (Redis driver)** After `SCRIPT FLUSH`, a release still succeeds. A connection whose ACL user may not run `EVAL` can release afterwards, which proves the fallback loaded the release script.
- **AC-10 (cache driver)** Release never creates a key: on the array store, releasing an allowed attempt whose window key is missing leaves the key missing. Otherwise the Redis and file stores would create it with -1 and no expiry.
- **AC-11 (cache driver, Redis cache store)** Release works when the phpredis connection serializes values: after five allowed attempts at T0+10, release one, and the sixth attempt is allowed.
- **AC-12 (failures)** These make `release()` of an allowed attempt throw `StorageUnavailableException`:
  - a cache store that throws on `get` or on `decrement`, with the store's exception as `previous`
  - Memcached failing to read the window, which its `get` reports only through the result code (as in SPEC-002 AC-20)
  - a Redis driver whose connection points at a closed port
- **AC-13 (concurrency)** 50 processes make 20 attempts each on one counter, with max 100 and the time frozen at T0+10. The 25 odd-numbered processes release every attempt they were allowed, right after it.
  - Kept attempts (allowed minus released) never exceed 100.
  - The stored count equals the kept attempts exactly.
  - Kept attempts are at least 75 with the Redis driver: only the 25 releasing processes can inflate its atomic count.
  - Kept attempts are at least 50 with the cache driver on Memcached and on the Redis cache store. Its in-flight increments inflate the count too (ADR-0016's `max − workers`).
- **AC-14 (model-based)** The SPEC-003 AC-8 traffic, 2,000 seeded steps, with releases:
  - After each step, with probability 1/4, one allowed attempt that is still held is released on both drivers. It is the one just made or an earlier one, possibly from an older window.
  - Every attempt of the Redis driver equals the reference (the cache driver on the array store): allowed, remaining, retryAfter and window index.
  - In every aligned window of every counter, allowed minus released never exceeds that window's max.
  - The traffic must release at least 300 attempts, at least 30 of them from an earlier window.

## Interfaces and files
- `src/Engine/Contracts/LimiterStore.php`: adds `release(Attempt $attempt): void`, as in ADR-0005.
- `src/Engine/Stores/RedisLimiterStore.php`: a release script, sharing the `EVALSHA`/`EVAL` runner with `attempt()`.
- `src/Engine/Stores/CacheLimiterStore.php`: `release()`.
- Tests:
  - `tests/Contract/LimiterStoreContractTest.php`: AC-1 to AC-6
  - `tests/Feature/Engine/CacheLimiterStoreTest.php`: AC-7 (cache store), AC-10, AC-12 (throwing store)
  - `tests/Drivers/RedisLimiterStoreTest.php`: AC-7 (closed port), AC-8, AC-9, AC-12 (closed port)
  - `tests/Drivers/CacheLimiterStoreServicesTest.php`: AC-11, AC-12 (Memcached)
  - `tests/Drivers/RedisConcurrencyTest.php` and `tests/Drivers/CacheLimiterStoreConcurrencyTest.php`: AC-13, with a release mode in `tests/Support/concurrency-worker.php`
  - `tests/Drivers/RedisModelTest.php`: AC-14

## Out of scope
- Which attempts to release, and in which order: the middleware's rule loop (SPEC-007, SPEC-010). Dry-run rules never reject and are never released (ADR-0012, SPEC-011).
- Logging and events when a release fails (SPEC-012).
- Store guarantees: `release()` has the same per-store guarantees as `attempt()`, including SPEC-002's decision on the file, APCu and SQLite stores.

## Edge cases and failure modes
- **Window changes between the attempt and the release.** The attempt's window index decides, so the release lands where the count is (AC-3). A release from a window older than the previous one changes nothing any decision reads. The Redis script may already have deleted that field, and then the release does nothing.
- **Expired or evicted keys.** The release does nothing (AC-5, AC-10). If the key was evicted and recreated by later attempts, the release takes one of their counts instead: one request looser, once. Evictions are rare on a store sized for the workload.
- **Concurrency.** Between an attempt and its release, the counter holds one extra count, so a request at the exact limit can be rejected early. ADR-0008 accepts this, and AC-13 bounds it.
- **Store down during release.** `StorageUnavailableException`; the rejection stands and the extra count stays until the window expires (AC-12).
- **Double release** takes another request's count, unless the count is already 0 (AC-4). Callers release each attempt once.
- **Cost.** Only rejected requests with earlier matched rules pay for release: one round trip per released rule with the Redis driver, two with the cache driver.

## End-to-end verification
- `composer test`, `composer analyse`
- `docker compose up -d && composer test:drivers` (contract suite on every store, AC-13 and AC-14)
- `composer test:mutate` ≥ 85%

## Open questions
None. Choices made while drafting from the ADRs, open to change at review:
- **Read before decrementing in the cache driver.** One more round trip per release, but no stray keys without a TTL and no counts below 0. A plain `decrement` would be one round trip and leave both.
- **Release throws on storage failure** instead of swallowing it, so SPEC-012 can log it and dispatch its event. `attempt()` swallows a failed undo (SPEC-002 AC-18), because a throw there would turn a rejection into fail-open. A failed release cannot change the decision.
