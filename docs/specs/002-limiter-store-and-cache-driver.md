# SPEC-002: LimiterStore contract and the generic cache driver

- Status: In progress
- ADRs: ADR-0005, ADR-0006, ADR-0009, ADR-0010, ADR-0016
- Roadmap phase: 1

## Problem
SPEC-001 decides allow or reject from two counts. Nothing stores those counts yet. The package needs one storage contract that every driver implements. It also needs a first driver that works on **any** Laravel cache store, because many apps do not run Redis (ADR-0005).

## Behavior
- `LimiterStore::attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt` counts one request for a rule and identity, and returns the decision.
- The cache driver keeps one cache key per window (ADR-0006): `{prefix}c:{ruleId}:{windowSeconds}:{identityHash}:{windowIndex}`, built only by `KeyBuilder`.
- **Increment first, then decide.** `add(key, 0, ttl)` creates the current window key with its TTL if it is missing. `increment(key)` counts this request atomically and returns the new count. The count before this request is `new count − 1`, the previous window's count comes from `get`, and SPEC-001 decides.
  - A rejected request is decremented back, so it is not counted (ADR-0006).
  - Because the increment is atomic, concurrent requests can never push the allowed total above the limit.
  - In-flight increments of requests that end up rejected can cause a few early rejections at the limit boundary. ADR-0016 accepts this: `max − workers ≤ allowed ≤ max`.
- Window keys expire `2 × window + 5` seconds after they are created: long enough to serve as the previous window, plus slack for clock skew.
- Time comes from the injected `Clock` in integer microseconds, so window index and elapsed time are exact integer arithmetic. In production the clock is Laravel's `Date::now()`, so `travel()` works in tests. Engine code never reads the clock itself (CLAUDE.md, enforced by `tests/Arch`).
- `CounterKey` only accepts an identity **hash** (64 lowercase hex characters), so a raw IP, token or email can never reach a cache key (ADR-0009). Hashing is SPEC-008's job.
- Storage failures (an exception from the store, or `increment` returning something other than an integer, which is how Memcached reports that it is down) raise `StorageUnavailableException`. The failure policy (ADR-0010, SPEC-012) decides what to do.

## Acceptance criteria
Window 60 s and max 5 unless stated. Times are offsets from T0 = 1,800,000,000 s, a multiple of 60, 30 and 3600. **Contract** criteria run against every store: array, file, database on SQLite, MySQL and PostgreSQL, Memcached, and Redis and Valkey as cache stores (phpredis and predis).

- **AC-1 (contract)** A fresh counter at T0+10 allows the first attempt: allowed, remaining 4, retryAfter 0, limit 5, window 60, window index 30,000,000.
- **AC-2 (contract)** Five attempts at T0+10 are allowed with remaining 4, 3, 2, 1, 0. The sixth is rejected with remaining 0 and retryAfter 62: 50 s to the next window, then 12 s until 5 × (60 − e) / 60 + 1 ≤ 5.
- **AC-3 (contract)** Rejected attempts are not counted: after the five allowed attempts and three rejected ones at T0+10, an attempt at T0+72 is allowed. Had the three rejections counted, the previous window would hold 8 and still reject.
- **AC-4 (contract)** The previous window counts in proportion, with max 10:
  - Ten attempts at T0+10 are allowed.
  - At T0+60 the next attempt is rejected (estimate 10) with retryAfter 6.
  - At T0+90 (estimate 5), five attempts are allowed with remaining 4, 3, 2, 1, 0, and the sixth is rejected with retryAfter 6.
- **AC-5 (contract)** Two windows later everything is forgotten: after five attempts at T0+10, an attempt at T0+120 is allowed with remaining 4.
- **AC-6 (contract)** Counters are isolated. After five attempts on rule "1" and identity A at T0+10, a first attempt is still allowed with remaining 4 for:
  - rule "1", identity B
  - rule "2", identity A
  - rule "1", identity A, window 86,399, after five attempts with window 86,400. Both windows have index 20,833 at T0+10, so only the window length in the key keeps them apart.
- **AC-7 (contract)** A lower limit applies at once, because counters are kept when max changes: after three attempts with max 5 at T0+10, an attempt with max 3 is rejected.
- **AC-8 (contract)** Window edges are exact. An attempt at T0+59.999999 has window index 30,000,000, and one at T0+60.000000 has 30,000,001. Five attempts at T0+59.999999 make an attempt at T0+60 rejected, because the previous window still weighs 5 × 60 / 60. Time within a window is sub-second too. With max 4 and window 1:
  - four attempts at T0+10 are allowed
  - at T0+11.1 an attempt is rejected (the previous second still weighs 3.6)
  - at T0+11.3 one is allowed (2.8)

  A driver that truncates time to whole seconds fails this.
- **AC-9 (cache driver)** On the array store, the current window key is `{prefix}c:1:60:{hash}:30000000`. After five allowed attempts and one rejected attempt at T0+10 it holds 5.
- **AC-10 (cache driver)** Window keys expire 2 × 60 + 5 = 125 s after creation: using Laravel's clock and `travelTo`, the key still exists 124.5 s after the first attempt and is gone after 125.5 s. The checks sit half a second on either side, so a TTL of 124 or 126 s fails.
- **AC-11 (cache driver)** These make `attempt()` throw `StorageUnavailableException`:
  - a store that throws on `increment`, with the store's exception as `previous`
  - a store whose `increment` returns `false`
  - a database store whose query fails, for example a missing cache table. Database exceptions carry string SQLSTATE codes such as `HY000`, so they must not be passed on as the exception's integer code.
- **AC-12 (KeyBuilder)** Prefix `dt:`, rule "42", window 60, window index 30,000,000 and hash `h` (64 hex characters) give exactly `dt:c:42:60:h:30000000`. The prefix may be empty, and is at most 32 characters from `A-Z a-z 0-9 : _ . -`; anything else throws `InvalidArgumentException`. Memcached keys cannot contain spaces or control characters.
- **AC-13 (CounterKey)** `CounterKey` throws `InvalidArgumentException` when:
  - the rule id is empty
  - the rule id is longer than 64 characters
  - the rule id contains characters outside `a-z 0-9 _ -`. Lowercase only: MySQL's default collation compares cache keys case-insensitively, so rules "Login" and "login" would share a counter.
  - the identity hash is not exactly 64 lowercase hex characters (so `"203.0.113.9"` or an email address is rejected)
- **AC-14 (clock)** `LaravelClock::microseconds()` follows Laravel's time travel: after `travelTo` 2027-01-01 00:00:00.250000 UTC it returns 1,798,761,600,250,000.
- **AC-16 (contract)** `attempt()` with max < 1, window < 1 or window > 86,400 (ADR-0015's limit) throws `InvalidArgumentException` **before touching the store**. A valid attempt afterwards is still the first: remaining 4. Validating only inside SPEC-001's formula would leave a phantom count from the increment. The smallest valid values work: with max 1 and window 1, the first attempt is allowed with remaining 0, and the second is rejected with retryAfter 2 (the previous window still weighs 1 at the next window's start).
- **AC-17 (cache driver)** Stored counts are read defensively:
  - A negative count in either window key, which only an outside decrement could leave, is read as 0. The attempt is allowed with remaining 4 instead of throwing.
  - A count stored as a numeric string, which is how Redis and Memcached return values, is read as a number.
- **AC-18 (cache driver)** If undoing a rejected attempt's increment fails, `attempt()` still returns the rejection instead of throwing. Throwing would let the fail-open policy allow a request the limiter rejected. The window keeps one extra count: stricter, never looser.
- **AC-19 (cache driver, Redis cache store)** Counting works when the phpredis connection serializes values (Laravel's `serializer` option). Otherwise `add` would store a serialized 0 that `INCRBY` cannot increment, and every attempt would fail. Laravel's own rate limiter bypasses serialization the same way.
- **AC-20 (cache driver, Memcached)** If reading the previous window fails, `attempt()` throws `StorageUnavailableException`. It must not silently count the previous window as 0: Memcached's `get` returns null on errors too, so the result code tells them apart.
- **AC-15 (concurrency, Memcached and Redis cache store)** 50 separate processes make 20 attempts each on one key with max 100 and the time frozen at T0+10:
  - at most 100 attempts are allowed and at least 50 (ADR-0016)
  - the stored count equals the number allowed

## Interfaces and files
- `src/Engine/Contracts/LimiterStore.php`: `attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt`. `release()` is added by SPEC-004.
- `src/Engine/Contracts/Clock.php`: `microseconds(): int` (Unix time).
- `src/Engine/CounterKey.php`: `final readonly`, `string $ruleId`, `string $identityHash`.
- `src/Engine/Attempt.php`: `final readonly`:
  - `CounterKey $key`
  - `int $limit`
  - `int $windowSeconds`
  - `int $windowIndex`
  - `bool $allowed`
  - `int $remaining`
  - `int $retryAfter`
- `src/Engine/KeyBuilder.php`: `final readonly`, `__construct(string $prefix = 'dt:')`, `counter(CounterKey $key, int $windowSeconds, int $windowIndex): string`.
- `src/Engine/Exceptions/StorageUnavailableException.php`
- `src/Engine/Stores/CacheLimiterStore.php`: `__construct(Repository $cache, KeyBuilder $keys, Clock $clock, SlidingWindowCounter $counter = new SlidingWindowCounter)`.
- `src/Support/LaravelClock.php`: outside `Engine`, so the Engine arch rules still forbid clock reads there.
- Tests:
  - `tests/Contract/LimiterStoreContractTest.php`: group `contract`. Local stores always; service stores when `DYNAMIC_THROTTLE_DRIVERS=1`, which `composer test:drivers` sets.
  - `tests/Feature/Engine/CacheLimiterStoreTest.php`, `tests/Unit/Engine/KeyBuilderTest.php`, `tests/Unit/Engine/CounterKeyTest.php`, `tests/Feature/Support/LaravelClockTest.php`
  - `tests/Drivers/CacheLimiterStoreConcurrencyTest.php`
  - `tests/Support/FakeClock.php` and a worker script for the concurrency test

## Out of scope
- The Redis Lua driver, driver selection and config wiring: SPEC-003.
- `release()` and compensation: SPEC-004.
- Identity hashing and scopes: SPEC-008.
- Telemetry: SPEC-015.
- Health warnings about file, APCu and database stores: SPEC-016.

## Edge cases and failure modes
- **Missing keys.** Stores differ on `increment` of a missing key: Redis creates it without a TTL, and Memcached and the database store fail. `add` first makes every store behave the same.
- **Rollback.** Only a key this request just incremented is decremented. A key evicted or flushed in the microseconds between the increment and the undo is the exception: Redis, file and array stores then create it with -1 and no TTL. Reads treat negative counts as 0 (AC-17), but the next increment only brings the key back to 0, so that window allows one extra request, and the key never expires. SPEC-004's `release()` avoids the extra request by adding the count back when a decrement goes below 0 (SPEC-004 AC-15), and the undo could do the same.
- **Clock skew between servers.** It only shifts window edges slightly (ADR-0006). The +5 s on the TTL absorbs it.
- **Store down.** Any failure up to the decision raises `StorageUnavailableException`:
  - Memcached returns `false` from `increment` instead of throwing, so an integer check catches it.
  - Memcached's `get` returns null on errors, so the result code is checked (AC-20).
  - A failure reading the previous window after the increment succeeded counts the same way.

  An increment that already happened then stays in the window: at most one extra count, which is stricter, never looser. A failure while undoing a rejection keeps the rejection (AC-18).
- **Stores that are not safe under concurrency** (found in review, measured with 50 processes × 20 attempts at max 100). **Decided by the maintainer on 2026-09-24: keep supporting them and warn loudly.** ADR-0005 stands ("warnings, not exceptions"), so a fresh Laravel app still works out of the box, no worse than Laravel's own throttle on these stores. The per-driver guarantees table in the docs gives the numbers below, and SPEC-016's health check calls these stores unsafe under concurrency:
  - **file:** `FileStore::increment` reads and then writes without a lock, so concurrent requests on one server lose counts. 927 to 1000 of 1000 were allowed.
  - **apc:** `ApcStore` has no `add`, so Laravel falls back to get-then-put, and two requests starting a window can reset each other's count. Not measured.
  - **database on SQLite:** the count stays exact, but 59–82% of attempts failed with "database is locked" under contention. That is Laravel's default setup (SQLite with the database cache store), and fail-open turns those failures into allowed requests.
  - MySQL 8.4 and PostgreSQL 16 allowed exactly 100 with no errors.
- **Test isolation on shared services.** Every contract test uses a unique key prefix, so runs never see each other's counters.

## End-to-end verification
- `composer test` (contract suite on the array, file and SQLite stores), `composer analyse`
- `docker compose up -d && composer test:drivers` (contract suite on every service store, plus the concurrency test)
- `composer test:mutate` ≥ 85% for everything in `src/Engine`

## Open questions
None. Choices made while drafting from the ADRs, open to change at review:
- **Integer microsecond clock.** Exact window arithmetic; matches Redis `TIME`.
- **`CounterKey` takes a hash, not the raw identity.** Raw identities can't reach keys, and the choice of hash stays with SPEC-008.
- **`StorageUnavailableException`** is the one exception type drivers throw for storage failures.
