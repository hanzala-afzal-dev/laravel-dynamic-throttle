# SPEC-028: The manifest's APCu cache on PHP-FPM

- Status: In progress
- ADRs: ADR-0007, ADR-0010, ADR-0016, ADR-0023
- Roadmap phase: 3

## Problem
PHP-FPM starts every request with nothing in memory. So each request reads the manifest from the cache store and decodes it: about 0.4 ms at p50 for 100 rules, most of the package's own time. With the manifest read per request, the package's p95 is about 1 ms, against 0.3 ms with it in memory (`benchmarks/results/phase-3-one-round-trip.md`). ADR-0007's L1 keeps the decoded manifest in APCu, which a server's PHP-FPM workers share. The Phase 3 exit criterion asks for under 0.5 ms at p95 on PHP-FPM with APCu.

## Behavior

### The read path: L0, then L1, then L2
1. **L0**, the process's memory: a manifest read within `refresh_seconds` is used as it is, as today.
2. **L1**, in APCu, holds the last manifest this server read from the store:
   - its version, rules and exemptions, whether it is stale, and when it was read;
   - never the rule index, which is rebuilt from the rules, as every loaded manifest's is (ADR-0023).

   An entry read within `refresh_seconds` is fresh. The request builds the manifest from it, and keeps that in L0 with the entry's read time, so L0 never outlives L1's freshness.
3. **L2**, the cache store, is otherwise read and decoded as today. The request then writes what it read to L1, with the time now.

So a change reaches every worker of every server within `refresh_seconds`, as ADR-0007 says. A server reads the store about once per `refresh_seconds`, where today each PHP-FPM request reads it.

### A missing or unreadable manifest (ADR-0007, ADR-0010)
- It is reported, and rebuilt after the response, as today.
- The request then uses the last known manifest, however old, marked stale: L0's or L1's, whichever has the higher version. Versions only grow (SPEC-006 AC-3), so a worker never trades newer rules for its own older ones.
  - The stale manifest is written back with the time now, so the store is tried again after `refresh_seconds`, by one request of the server, not by every request.
  - This keeps fail-closed rules answering 503 through a store outage on PHP-FPM, where today every request finds no rule.
- With no manifest anywhere, no rule applies, as today.

### When L1 is used
- APCu is loaded and enabled. The CLI needs `apc.enable_cli=1`, so queue workers and Octane on Swoole or RoadRunner use it only then.
- `refresh_seconds` is above 0. At 0, every request reads the store, as ADR-0007's fallback says.
- `DynamicThrottle::fake()` is off, so a test never reads another test's manifest.

Otherwise the read path is today's.

### Entries
- One entry per app of the server, and per installed version of the package. Its key comes from `KeyBuilder` and names both:
  - the app by its base path, as the cooldown's APCu entries do (SPEC-012), since a server's PHP-FPM pools share APCu;
  - the package's commit, as Composer installed it, else its version, so an upgrade, even to another commit of a branch, never reads objects written by other classes.
- An entry is kept for a day after it is written. That bounds the last known manifest's age, and lets old apps' and old versions' entries expire.

### Failures
APCu may be full or fail, a fetch may throw, or an entry may not have the shape of a snapshot. Each of these is a miss:
- the request reads the store, as today;
- a bad entry is deleted;
- nothing throws.

## Acceptance criteria
The APCu tests run in `tests/Apcu` with `apc.enable_cli=1` (`composer test:apcu`), with a FakeClock at T0.

- **AC-1 (a hit)** With a fresh L1 entry and an empty L0, a request gets the entry's rules and reads nothing from the cache store.
- **AC-2 (a fill)** With an empty L1, the first request reads the store once and fills L1. A second request, with L0 emptied as PHP-FPM empties it, reads nothing from the store.
- **AC-3 (freshness)** An entry read at T0 is used until T0 + `refresh_seconds`. After that, the next request reads the store, and a rule saved at T0 + 1 applies. A read time in the future, after the clock went back, is not fresh, in L1 as in L0.
- **AC-4 (the last known manifest)** The store loses the manifest while L1 holds one, and L0 is empty:
  - the request uses L1's rules, marked stale, reports once and schedules one rebuild;
  - the next request within `refresh_seconds` reads nothing from the store;
  - with a manifest in L0 too, as in a long-running worker, the one with the higher version is used.
- **AC-5 (fail-closed through an outage)** With the cache store failing and L0 empty, a fail-closed rule from L1 answers 503.
- **AC-6 (apps)** Two apps with different base paths never read each other's entry.
- **AC-7 (versions)** An entry written under another package version is never read. The provider keys the entry by the app's base path and the commit Composer installed.
- **AC-8 (off)** With `refresh_seconds` 0, or with `DynamicThrottle::fake()`, nothing is written to APCu, and the read path is today's.
- **AC-9 (bad entries)** An entry that is not a snapshot is ignored, deleted and replaced, and nothing throws. The cases:
  - a string;
  - an array;
  - an object of another class;
  - an object that throws when APCu reads it;
  - a snapshot whose rules are not compiled rules, or whose exemptions are not compiled exemptions.
- **AC-10 (APCu full)** When APCu cannot store the entry, the request still gets the store's rules, and nothing throws.
- **AC-11 (the index)** An L1 entry holds no rule index, and a manifest built from one matches exactly as the decoded manifest does.
- **AC-12 (the benchmark)** `MiddlewareBench` gets a case "PHP-FPM with APCu", with L0 emptied before each request. On the array store, the package's own time is under 0.5 ms at p95: the Phase 3 exit criterion.
- **AC-13 (hard rules)** The arch tests hold: no database, no wall clock. Only `ApcuFailureMemory` and `ApcuManifestCache` call APCu, with keys from `KeyBuilder`.

## Interfaces and files
- `src/Manifest/ManifestSnapshot.php` (new, internal): what L1 holds.
  - Fields: the manifest's version, rules, exemptions and state, and the read time in microseconds.
  - It turns into a `Manifest`, and comes from one.
- `src/Manifest/Contracts/LocalManifestCache.php` (new, internal): `fetch(): ?ManifestSnapshot` and `store(ManifestSnapshot)`. Neither throws.
- `src/Support/ApcuManifestCache.php` (new): the APCu implementation, next to `ApcuFailureMemory`.
- `src/Manifest/ManifestRepository.php`: L1 between L0 and L2. It takes an optional `LocalManifestCache`, and with none behaves as today.
- `src/Engine/KeyBuilder.php`: `localManifest(string $app, string $version)`.
- `src/DynamicThrottleServiceProvider.php`: gives the repository the APCu cache when L1 is used, keyed by the app's base path and the package's commit from Composer's `InstalledVersions`.
- `benchmarks/Http/MiddlewareBench.php` and `phpbench.json`: the "PHP-FPM with APCu" case, with `apc.enable_cli=1`.
- Tests:
  - `tests/Apcu/`: AC-1 to AC-11, with APCu itself;
  - `tests/Feature/Manifest/ManifestRepositoryTest.php`: AC-3's clock going back, in L0;
  - `tests/Unit/Engine/KeyBuilderTest.php`: the key;
  - `tests/Arch`: AC-13.
- README and CHANGELOG: APCu is recommended on PHP-FPM, with what it saves.

## Out of scope
- **Building only the rules a request can match**, from an entry that stores the index and each rule separately. It is the fallback if AC-12 misses.
- **PHP files cached by OPcache as L1,** ADR-0007's deferred alternative.
- **A lock against several workers refreshing L1 at once.** See the edge cases.

## Edge cases and failure modes
- **Several workers refresh at once.** Each writes what it read, and the last write wins. A worker that read an older version can overwrite a newer one, which then waits for the next refresh: one more `refresh_seconds` at most.
- **Time** comes from the Clock, never `time()`. APCu's own TTL only expires entries after a day.
- **The clock goes back,** after an NTP step for example. A read time in the future is not fresh, so the next request reads the store. Otherwise L0 would keep its manifest until the clock caught up, as it does today, and L1 would hold a whole server's.
- **A large manifest:** 100 rules serialize to about 36 KB. An entry APCu cannot hold is a miss (AC-10).
- **`apc.serializer`:** the tests use PHP's own serializer, APCu's default. Another one, such as igbinary, must support enums, which the rules hold.
- **A deploy in place that keeps PHP-FPM running:**
  - An entry written by another commit of the package is never read (AC-7).
  - Within one version, an app's own code changes nothing that L1 holds.
- **The CLI:** without `apc.enable_cli=1`, APCu is off. With it, each process has its own APCu, so L1 only adds a copy per process.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `composer test:apcu`
- `composer test:mutate`, if `src/Engine` changes (`KeyBuilder`)
- `composer bench`: `MiddlewareBench`, recorded in `benchmarks/results/`

## Decisions
Recorded as recommended (ADR-0027), except the sixth, which the maintainer answered on 2026-10-01. The maintainer can veto the others in the PR.
1. **L1 holds the decoded manifest's parts, not its JSON.** On the benchmark's 100 rules:
   - fetching them from APCu costs about 0.11 ms at p50, against 0.40 ms to decode the JSON;
   - fetching the JSON from APCu, then decoding it, saves nothing.
2. **The index is rebuilt after the fetch** (ADR-0023). Storing it would cost as much to fetch: 0.111 ms against 0.116 ms.
3. **The key names the commit Composer installed**, from `InstalledVersions`, else the package's version.
   - With OPcache, as on any production PHP-FPM, that costs 1 to 3 µs per request. The draft measured 1.5 ms in a process without OPcache, which compiles Composer's list of installed packages on every call, and so recommended a constant.
   - A constant bumped at each release would cost nothing. But a forgotten bump, or two commits of one branch, would read objects that other classes wrote.
4. **No new config key.** L1 follows APCu, as the cooldown does (SPEC-012), and `refresh_seconds` 0 switches it off.
5. **No lock when L1 goes stale.** The refresh is at most one extra store read and decode per worker, once per `refresh_seconds`.
6. **L1's lifetime: fresh for `refresh_seconds`, kept a day** (answered by the maintainer).
   - ADR-0007's table gives L1 a TTL of `refresh_seconds`, and its "Missing manifest" section uses a last known manifest from L0 or L1. An entry that expired after `refresh_seconds` would hold no last known manifest 2 seconds into an outage.
   - So the table's TTL is L1's freshness, and the entry is kept a day as the last known manifest, as L0 keeps its manifest in a long-running worker. ADR-0007's decision record notes this reading.
7. **The last known manifest is the newer of L0's and L1's.** Taking L0's first, as the draft said, could let a long-running worker write its older manifest over a newer one in L1, which every other worker would then use.
