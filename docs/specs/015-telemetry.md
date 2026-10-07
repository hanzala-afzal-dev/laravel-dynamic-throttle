# SPEC-015: Telemetry: the buffer, the flush, the prune and the events

- Status: In progress
- ADRs: ADR-0013, ADR-0004, ADR-0005, ADR-0010, ADR-0012, ADR-0017, ADR-0020, ADR-0024, ADR-0026
- Roadmap phase: 3

## Problem
An admin cannot see how much traffic each rule sees, how often it blocks, or who it blocks, and a dry-run rule cannot be promoted on evidence: "it would have blocked N requests from M clients" (SPEC-011). Integrations have `RateLimitWouldBlock` and `StorageUnavailable`, but nothing for a real 429 or for a changed rule. ADR-0013 decides how to count all of this without slowing or breaking a request, and without writing to the database on the request path.

## Behavior
**After the response, each request's numbers go to the counter store** (ADR-0013 points 1 and 2):
- For each rule the store checked, per minute: `hits`, the requests it checked; `blocks`, those an enforcing rule rejected with a 429; and `would_blocks`, those a dry-run rule would have rejected (ADR-0012).
  - A rule that did not check the request records nothing: exempt, skipped, over the cap, not reached, or not checked because the store failed or is in its cooldown.
  - Requests the middleware lets through untouched record nothing.
- On the Redis driver (decision 4):
  - **The top offenders:** per rule and hour, the identity hashes the rule blocked or would have blocked, with how often. Reads give the top 100.
  - **The distinct clients:** per rule and hour, how many different clients those were, which answers SPEC-011's "N requests from M clients".
  - The cache driver records neither, and says so: there is no sorted set or HyperLogLog on a generic cache store.
- Everything expires after 2 hours.
- The middleware collects the numbers in `handle()` and writes them in `terminate()` (ADR-0004 point 4), which PHP-FPM and Octane run after sending the response. On Redis the write is one round trip, a Lua script, outside Redis Cluster. The counting stays one round trip before the response (ADR-0026, decision 3).
- **Sampling:** `telemetry.sample_rate`, from 0 to 1, 1 by default, records that share of requests, each weighted so that the counts stay unbiased (decision 6).
- `telemetry.enabled` turns recording off.
- **A failure never reaches the request** (ADR-0013 point 7, ADR-0020 point 1):
  - A write that fails is reported at most once per cooldown (SPEC-012).
  - While the counter store's cooldown runs, nothing is written.
  - A failed write never starts the cooldown, which only counting failures start (decision 7).

**`php artisan dynamic-throttle:flush-metrics`**, which the scheduler runs every minute (ADR-0013 point 4):
- It moves each complete minute's numbers into `dynamic_throttle_metrics_hourly`, one row per rule and hour, the hour in UTC (decision 9).
- A minute is complete once the next one has ended, so a request that finishes late still lands before the minute is read.
- **It is idempotent:**
  - Each row remembers the last minute it took in, so a run that is repeated, or that stopped halfway, never adds a minute twice.
  - Two runs at once never add a minute twice either (decision 8).
- On Redis, it also sets each row's `clients` to the hour's distinct count.
- It reads at most the last 2 hours, which is how long the numbers live in the store, starting after the last minute it flushed.

**`php artisan dynamic-throttle:prune --hours=720`**, which the scheduler runs daily, deletes the rows of hours that ended more than that many hours ago (ADR-0013 point 5). `telemetry.retention_hours` sets the scheduled run's hours, 720 (30 days) by default.

**The events** (ADR-0013 point 6, decision 11):
- **`RateLimitExceeded`:**
  - When an enforcing rule rejects a request, it is dispatched after the response.
  - It carries the same values as `RateLimitWouldBlock`: the rule, its limit and window, the scope, the client's identity hash, the `Retry-After`, the method and the path.
  - The order is: the would-block events, then `RateLimitExceeded`, then `StorageUnavailable`, as evaluation met them.
- **`RuleChanged`:**
  - When a rule is created, updated (enabled and disabled included) or deleted, it is dispatched after the commit.
  - It carries the rule's id and name, the change, and the changed attributes before and after.
  - A change that rolls back dispatches nothing.
- **Listeners:** both carry plain values, so queued listeners work. A listener that throws is reported, and neither the response nor the change is affected.
- Sampling and `telemetry.enabled` never apply to events.

## Acceptance criteria
Feature tests run on SQLite with the array cache store, and the Redis buffer runs against Redis and Valkey with phpredis and predis. The clock is frozen.

**Recording**
- **AC-1 (counts)** In one minute, a client sends 3 requests through three rules, in evaluation order:
  - a dry-run rule B, limit 1;
  - an enforcing rule A, limit 2;
  - an enforcing rule C, which only the requests A lets through reach.

  Then:
  - B's minute holds hits 3 and would_blocks 2;
  - A's holds hits 3 and blocks 1;
  - C's holds hits 2;
  - an exempt rule, a skipped rule, a rule over the cap, and a rule after a fail-closed stop record nothing.
- **AC-2 (after the response)** `handle()` writes nothing to the buffer. `terminate()` writes it. A middleware that terminates twice writes once, and one the app also lists globally records both of its runs, in one write.
- **AC-3 (one call on Redis)** On the Redis driver, outside Redis Cluster, `terminate()` makes one round trip for the request's telemetry, whatever the number of rules: 1, 3 and 10. On Redis Cluster, where a script may only touch one hash slot, it makes one call per key, with the same numbers.
- **AC-4 (offenders)** On Redis:
  - 150 clients are blocked or would-be-blocked by one rule in an hour, client k k times. The top 100 read back in order of count, with their counts.
  - Under 1,000 clients, the set never holds more than 200, and still gives 100.
  - The cache driver gives no offenders, and says the driver cannot keep them.
- **AC-5 (distinct clients)** On Redis, 3 clients blocked 10 times between them give a distinct count of 3. The cache driver gives none.
- **AC-6 (expiry)** Every key telemetry writes expires 7,200 seconds after it is first written, on both drivers.
- **AC-7 (sampling)** With a seeded random source:
  - with `sample_rate` 0.25, exactly the requests the source picks are recorded, each with weight 4;
  - with 0.3, each recorded request weighs 3 or 4, and over 100,000 draws the total is within 2% of the requests made;
  - 0, or `telemetry.enabled` false, records nothing.
- **AC-8 (failures)** In `terminate()`:
  - A buffer that throws is reported once per cooldown. The events are still dispatched.
  - While the counter store's cooldown runs, the buffer is not called.
  - A failed write does not start the cooldown.
- **AC-9 (untouched)** A request let through by the kill switch, `except`, bypass or no matched rule records nothing.

**Flushing**
- **AC-10 (hourly rows)** With minutes 10:58 and 10:59 recorded:
  - a flush at 11:01:30 adds both to the 10:00 row, the hour in UTC, and creates the row when it is missing;
  - minutes 11:00 and 11:01 wait for a later run.
- **AC-11 (idempotent)** A second flush changes no row. A flush that fails after its first minute, then runs again, adds every minute exactly once.
- **AC-12 (concurrent)** Two flushes over the same minutes, interleaved, add each minute once.
- **AC-13 (clients)** On Redis, a row's `clients` is set to the hour's distinct count, and later flushes set it again rather than add to it, never lower. A count gone by the row's first flush leaves it null where clients were blocked, and 0 where none was. On the cache driver it stays null.
- **AC-14 (window)** With no previous flush, a flush reads at most the last 120 minutes. After one, it starts at the minute after the last one flushed.
- **AC-15 (rules)**
  - On Redis, a deleted rule's minutes are flushed under its id.
  - On the cache driver, the flush reads the minutes of the rules in the table.
  - Rows for deleted rules are kept until pruned.
- **AC-16 (failures)**
  - **The table is missing:** the flush prints how to publish and run the migration, exits 1, and leaves the buffer as it was.
  - **The database or the store fails:** the flush reports it, exits 1, and the next run catches up.

**Pruning and scheduling**
- **AC-17 (prune)**
  - `dynamic-throttle:prune --hours=720` deletes the rows of hours that ended more than 720 hours ago, and keeps the rest.
  - `--hours` must be a whole number from 1 to 1,000,000, about 114 years; anything else prints why and exits 1.
- **AC-18 (schedule)**
  - With `telemetry.enabled` and `telemetry.schedule`, the scheduler runs `flush-metrics` every minute, without overlapping, and `prune` daily with `telemetry.retention_hours`, at most 1,000,000.
  - Either key false registers neither.

**Events**
- **AC-19 (RateLimitExceeded)**
  - A request an enforcing rule rejects dispatches one `RateLimitExceeded` after the response, with the rule's id, name, limit, window and scope, the identity hash, the `Retry-After`, the method and the path.
  - It survives `serialize()`.
  - A response override dispatches it too.
- **AC-20 (order)** A dry-run rule that would block, then an enforcing rule that rejects, whose release then fails, dispatch `RateLimitWouldBlock`, `RateLimitExceeded`, then `StorageUnavailable`.
- **AC-21 (RuleChanged)**
  - Creating, updating and deleting a rule dispatch one `RuleChanged` each, after the commit, with the change and the changed attributes before and after.
  - Disabling a rule is an update of `enabled`.
  - A transaction that rolls back dispatches nothing.
  - A listener that throws is reported, and the change stands.
  - A change made on a rule loaded with some of its columns stands and compiles, and dispatches those columns, with `''` for a name it was loaded without.

**Request path**
- **AC-22 (rules)** The arch tests hold:
  - the buffer and the middleware never touch the database;
  - engine code never reads the clock itself;
  - every key comes from `KeyBuilder`.

## Interfaces and files
- `src/Engine/Telemetry/` (new, internal):
  - `TelemetryRecord`: a rule's outcome for one request.
  - `Sampler`: the decision and the weight, from a `Random\Randomizer`.
  - `TelemetryMetric`: `hits`, `blocks` and `would_blocks`, as the table names them.
  - `RedisTelemetryBuffer` and `CacheTelemetryBuffer`: they record, and read back for the flush.
- `src/Engine/Contracts/TelemetryBuffer.php` (new, internal):
  - `record(int $minute, int $weight, list<TelemetryRecord> $records): void`;
  - `counts(int $minute, ?list<string> $ruleIds): array<string, MinuteCounts>`;
  - `offenders(string $ruleId, int $hour, int $limit = 100): ?array<string, int>`;
  - `clients(string $ruleId, int $hour): ?int`;
  - `flushedThrough(): ?int` and `markFlushed(int $minute): void`.
- `src/Engine/KeyBuilder.php`: the telemetry keys, under `t:`.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: collects the records in `handle()`, writes them and dispatches `RateLimitExceeded` in `terminate()`.
- `src/Support/LimiterStoreFactory.php`: builds the buffer on the counter store's driver and target.
- `src/Support/MetricsFlusher.php` (new, internal): the flush.
- `src/Console/FlushMetricsCommand.php` and `PruneCommand.php` (new).
- `src/Models/HourlyMetric.php` (new).
- `database/migrations/…_create_dynamic_throttle_metrics_hourly_table.php` (new):
  - columns `id`, `rule_id`, `hour`, `hits`, `blocks`, `would_blocks`, `clients` (nullable) and `flushed_minute`;
  - unique on `rule_id` and `hour`, and an index on `hour`.
- `src/Events/RateLimitExceeded.php`, `src/Events/RuleChanged.php` and `src/Enums/RuleChange.php` (`created`, `updated`, `deleted`) (new).
- `src/Models/RuleObserver.php` (new): dispatches `RuleChanged` after the commit.
- Config:
  - `telemetry.enabled` (`DYNAMIC_THROTTLE_TELEMETRY`, true);
  - `telemetry.sample_rate` (`DYNAMIC_THROTTLE_SAMPLE_RATE`, 1);
  - `telemetry.schedule` (true);
  - `telemetry.retention_hours` (720).

## Out of scope
- Reading the metrics, the offenders and the clients: the JSON API (SPEC-017) and the dashboard's Activity page (SPEC-024).
- The health check's "metrics not flushed for more than 10 minutes" (SPEC-016), which reads `flushedThrough()`.
- Offenders and distinct clients on the cache driver (ADR-0013's revisit).
- An event for the emergency settings: the audit log records those changes (SPEC-013). An event can be added later without breaking anything.
- Pruning the audit log (SPEC-019).

## Edge cases and failure modes
- **The scheduler is not running:** the numbers expire from the store after 2 hours and are lost. SPEC-016's health check catches it.
- **Clocks differ between servers:** a server more than a minute behind writes into a minute already flushed, and those numbers are lost. NTP keeps clocks well within that.
- **Redis Cluster:** a request's telemetry keys live in many hash slots, so the write is one call per key there, as SPEC-027's counting is.
- **Under attack from many clients:**
  - Each rule's offender set keeps at most 200 entries. When a new client would make it 201, it is cut back to the top 100, so a new heavy hitter can still climb into the top.
  - The distinct count uses at most 12 KB per rule and hour.
- **Sampling** makes the counts estimates: unbiased, but noisy for rules that see few requests.
- **A request that ends in an exception before the evaluation,** or one strict mode rethrows, records nothing.
- **During an outage, telemetry under-counts:** a request whose own count or release fails starts the cooldown before its `terminate()`, so its numbers are not written, and neither are those of any request that ends while the cooldown runs (decision 7).
- **Sampling and the distinct clients:** the HyperLogLog adds each sampled client once, whatever its weight, so below a sample rate of 1 `clients` is a lower bound, while hits and blocks are scaled up.
- **The distinct count expires before its hour's last minutes:** it lives 2 hours from the hour's first block, a minute's numbers 2 hours from that minute. So a flush that catches up after more than an hour can find it gone: it never lowers a row's `clients`, and leaves a first count unknown where clients were blocked.
- **The last minute flushed** is kept for 2 hours from each flush, as long as the numbers it could tell the flush about. Without it, a flush reads the last 120 minutes, and the rows keep it from adding any twice.
- **The cache driver's write** is an `add()` and an `increment()` per rule and metric, each a round trip on a networked store such as Memcached, after the response. `TelemetryBench` measures it.
- **The databases:** the flush uses plain updates, inserts and an existence check, which every database Laravel supports compiles, SQL Server included. A row two flushes create at once is caught as a duplicate, and the minute is added once.
- **A middleware the app lists twice,** globally and on a route group, counts a request twice, so it records both runs, and dispatches both runs' events.
- **`RuleChanged` in nested transactions:** the events go out when the outermost transaction commits, in the order Laravel runs after-commit callbacks, which is the innermost transaction's first.
- **A rule loaded with some of its columns,** such as by `select(['id', 'enabled'])`, carries only those in `RuleChanged`, and `''` for a name it does not know. Nothing in the observer throws, so the change always compiles.
- **`--hours` above 1,000,000** would take the cutoff past what a date can count back, into the future, and delete every row, so the prune refuses it, and the schedule caps `telemetry.retention_hours` at it.
- **`DynamicThrottle::fake()`** records into its in-memory store, like the counters.

## End-to-end verification
- `composer test -- --filter=SPEC-015`, `composer test:drivers` for the Redis buffer, and `composer analyse`, `format:check` and `refactor:check`
- `composer test:mutate`, since `src/Engine` changes
- `composer bench`:
  - `MiddlewareBench` with telemetry on and off;
  - a new `TelemetryBench` for the write in `terminate()`.
- the review subagent: the engine, the request path, the public API (events, config, commands, table)

It ships in three stacked PRs (ADR-0027), each green on its own:
1. **The buffers:** the `TelemetryBuffer` contract, its Redis and cache drivers, and the telemetry keys. AC-3 to AC-6, the buffer's part of AC-1, AC-8, AC-14 and AC-15, and AC-22's rule for `Engine\Telemetry`.
2. **Recording and the events:** the middleware's records and their write in `terminate()`, the sampler, `RateLimitExceeded` and `RuleChanged`. AC-1, AC-2 and AC-7 to AC-9 through the middleware, and AC-19 to AC-21.
3. **The table, the flush and the prune:** the migration, `dynamic-throttle:flush-metrics`, `dynamic-throttle:prune` and their schedule, then the docs and the benchmark. AC-10 to AC-18, and AC-22's rule for the flush.

## Open questions
None. Every decision below is recorded as recommended (ADR-0027), for the maintainer to veto in the PR.

## Decisions
1. **`hits` counts the requests each rule checked, allowed or rejected.**
   - An enforcing rule's `blocks` and a dry-run rule's `would_blocks` are among its hits.
   - A count released after a later rule rejected the request (ADR-0021) is still a hit: the rule checked it.
2. **The numbers come from the evaluation, per rule:**
   - its allowed attempts give a hit;
   - the rejection gives a hit and a block;
   - each would-block gives a hit and a would-block.
3. **Telemetry is a second store call, after the response.**
   - ADR-0026's one round trip is the counting, on the response path, whose latency it was decided for.
   - ADR-0013 decided this second call, pipelined, after the response.
   - Doing the writes in the counting script would cost no extra call, but would break ADR-0013 point 1, which records after the response, so it would need a new ADR.
4. **Redis keeps the offenders and the distinct clients:**
   - the offenders as ADR-0013 says;
   - the distinct clients in a HyperLogLog per rule and hour, which SPEC-011 left to this spec for "N requests from M clients".
   - **Rejected:** the offender set's size as the client count, which the cut to 100 caps.
5. **The cache driver keeps one key per minute, rule and metric,** created with its time to live before it is incremented, as the counters are (ADR-0006).
   - Its flush reads the minutes of the rules in the table, since a generic cache store cannot list keys.
   - The Redis driver keeps one hash per minute, which the flush reads whole.
6. **Sampling picks whole requests, and weighs each one so the counts stay unbiased.**
   - At rate p, a picked request counts as ⌊1/p⌋, or ⌈1/p⌉ with probability equal to the fractional part of 1/p. At 0.25 that is always 4; at 0.3, 3 or 4, 3.33 on average.
   - **Rejected:** scaling at read time, which goes wrong when the rate changes mid-hour.
7. **A failed telemetry write never starts the cooldown.**
   - Only counting failures start it, since the cooldown changes what requests get (ADR-0010).
   - Writes are skipped while it runs, and their failures are reported once per cooldown.
8. **The flush is idempotent per row.**
   - Each row keeps `flushed_minute`, and a minute is added only with `WHERE flushed_minute < ?`. So repeated, interrupted and concurrent runs each add a minute once.
   - Revised after the review: a missing row is created by an insert after a check, and a duplicate from a concurrent flush retries the update. The draft's insert-or-ignore does not compile on SQL Server.
   - The last minute flushed is also kept in the store, which only saves reads: if the store is cleared, the rows still prevent adding a minute twice.
   - The scheduled run also uses `withoutOverlapping()`.
   - **Rejected:** deleting the buckets once flushed, which loses them when the database write fails, or counts them twice when the delete does.
9. **`hour` is in UTC.**
   - In local time, the hour that repeats when daylight saving time ends would merge two hours into one row.
   - This decides nothing for the other tables, which wait on the maintainer's decision before 0.1.0 (the roadmap).
10. **Rows outlive their rule, without a foreign key, until pruned.**
    - They answer "what did that rule block before it was deleted?", and the audit log says who deleted it.
    - **Rejected:** deleting them with the rule, as exemptions are.
11. **The events:**
    - `RateLimitExceeded` carries `RateLimitWouldBlock`'s values, so one listener can handle both.
    - `RuleChanged` carries `before` and `after` attribute arrays, as the audit log does: null before a creation and after a deletion, and only the changed attributes for an update. A rule loaded with some of its columns gives only those (the review).
    - Neither depends on `telemetry.enabled` or sampling, since an event with no listener costs nothing.
12. **The 10% budget of ADR-0013's fallback applies to the response path,** which `MiddlewareBench` measures with telemetry on and off.
    - The write after the response is measured on its own, with `TelemetryBench`, and stated in the PR.
    - If that write proves too costly, the maintainer decides on a lower default sample rate before 0.1.0, as the roadmap's fallback says.
