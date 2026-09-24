# SPEC-006: Manifest compiler and layered read path

- Status: Draft
- ADRs: ADR-0005, ADR-0006, ADR-0007, ADR-0008, ADR-0010, ADR-0020
- Roadmap phase: 2

## Problem
Rules live in the database (SPEC-005), but the request path may never query it (ADR-0007, CLAUDE.md). Rules must reach every request on every server through a compiled manifest in the cache. That manifest must heal itself when the cache is flushed, and a flushed cache must never start a stampede of database queries.

## Behavior
- **Compiler.** `ManifestCompiler::compile()`:
  - reads the enabled rules
  - skips and reports invalid rows, so one bad row never stops the others
  - sorts the rest by priority, then id (ADR-0008, ADR-0020 point 6)
  - writes one JSON manifest to the cache store, without a TTL (layer L2)

  It runs under a cache lock, so two compiles cannot overwrite each other out of order (ADR-0007). A compile that fails leaves the stored manifest as it was, and never writes an empty manifest because the database could not be read.
- **Version.** Each compile's version is an integer greater than every earlier one, even after the cache was flushed: `max(previous + 1, now in milliseconds)`. Readers treat any different version as new.
- **Triggers:**
  - A rule observer compiles after the database transaction commits (ADR-0007). A rolled-back save changes nothing. A failed compile there is reported, not thrown: the save has already committed, and the scheduled compile heals the manifest.
  - `php artisan dynamic-throttle:compile`, for deploy scripts.
  - A scheduled compile every five minutes heals a flushed or evicted manifest (open question 2).
- **Read path.** `ManifestRepository::current()` never touches the database:
  - **L0**, in memory: process-wide, and refreshed at most every `manifest.refresh_seconds` (default 2). On Octane and queue workers it spares most reads. On PHP-FPM memory does not outlive the request, so every request reads L2 (documented, ADR-0007).
  - **L2**, the cache store: the store the counters use (`dynamic-throttle.store`, or the default store, ADR-0005 point 6).
  - **L1 (APCu)** is not in this spec (open question 1).
- **Missing or unreadable manifest** (flushed, evicted, corrupt, or the store is down):
  - The repository serves the last-known manifest from L0 when it has one. Otherwise it serves an empty manifest, so no rule applies (ADR-0010 point 3).
  - It reports the problem.
  - One request, the one that wins a short lock, schedules a rebuild after the response is sent. That rebuild is the only database access allowed on a request thread (ADR-0007).
  - The repository never throws.
- **Keys** come from `KeyBuilder` (CLAUDE.md):
  - `{prefix}m`: the manifest
  - `{prefix}m:lock`: the compile lock
  - `{prefix}m:rebuild`: the rebuild lock

  Counter keys start with `c:`, so the two never collide.

## Acceptance criteria
Times use Laravel's clock (`travelTo`) for the compiler, and the package `Clock` for the read path.

### Compiling
- **AC-1** Compiling writes the enabled rules, sorted by priority, then id. Take these rules:
  - id 1: priority 100
  - id 2: priority 10
  - id 3: priority 100, disabled
  - id 4: priority 10

  The manifest's rules are ids 2, 4 and 1, in that order.
- **AC-2** Each compiled rule holds exactly these fields, with enum values as strings:
  - `id` (a string, as counters use it), `name`, `method`, `pattern`
  - `scope`, `scope_parameter`
  - `max_attempts`, `window_seconds`, `priority`
  - `mode`, `on_failure`, `on_missing_identity`
  - `response_override`

  There is no description and no timestamp.
- **AC-3** Versions only grow:
  - With the clock at 2027-01-01 00:00:00 UTC, the first compile has version 1,798,761,600,000.
  - A second compile in the same millisecond has 1,798,761,600,001.
  - After the manifest key is deleted, a compile one second later has 1,798,761,601,000.
- **AC-4** Invalid rows are skipped. Each is reported once through `report()`, naming the rule id and the reason, and the other rules still compile. These rows are invalid:
  - an unknown value in any enum column
  - `max_attempts` below 1
  - `window_seconds` below 1 or above 86,400
  - an empty pattern
  - a `header` or `custom` rule without `scope_parameter`
- **AC-5** Zero enabled rules compile to a manifest with no rules. That is different from a missing manifest: readers apply no rules, and schedule no rebuild.
- **AC-6** A compile that fails leaves the stored manifest untouched: first when reading the database throws, then when writing to the store throws. Each time `compile()` throws `ManifestCompileFailed`, with the cause as `previous`.
- **AC-7** Compiles run under the lock. While another owner holds `{prefix}m:lock`, `compile()` waits up to its lock timeout, then throws `ManifestCompileFailed` without writing.

### Triggers
- **AC-8** Saving, updating or deleting a rule compiles after the transaction commits:
  - Inside a transaction that rolls back, the manifest does not change.
  - After a commit, the version grows and the rules match the table.
- **AC-9** When the observer's compile fails (the store throws), the rule is still saved, and the failure is reported exactly once.
- **AC-10** `php artisan dynamic-throttle:compile`:
  - On success, it prints `Compiled manifest version N: M rules, K skipped.` and exits 0.
  - When compiling fails, it prints the reason and exits 1.
- **AC-11** The scheduler runs `dynamic-throttle:compile` every five minutes. With `manifest.schedule` set to `false`, it does not (open question 2).

### Reading
- **AC-12** With an empty L0, `current()` returns the manifest stored in L2, marked `fresh`.
- **AC-13** L0 spares reads:
  - Within `refresh_seconds` (2) of the last L2 read, `current()` answers from L0, and the store records no call.
  - 2 s after the last read, it reads L2 again, and serves a newer version if there is one.
  - With `refresh_seconds` 0, every call reads L2.
- **AC-14** With the manifest missing and a last-known manifest in L0, `current()` returns that manifest, marked `stale`, and schedules a rebuild.
- **AC-15** With the manifest missing and L0 empty:
  - `current()` returns an empty manifest, marked `missing`.
  - Across three calls, exactly one rebuild is scheduled, and it runs only when the app terminates (after the response).
  - After it runs, `current()` returns the rebuilt manifest.
- **AC-16** A corrupt manifest counts as missing, and is reported. Corrupt means any of: not JSON, a wrong shape, or an unknown enum value.
- **AC-17** A store that throws while reading also counts as missing. It is reported, and `current()` does not throw.
- **AC-18** No query runs before the response:
  - With a warm L2 and an empty L0, `current()` runs zero database queries.
  - With the manifest missing, it also runs zero before the app terminates. The rebuild's queries run after.
- **AC-19 (arch)** `Afzal\DynamicThrottle\Manifest` (the read path and the value objects) uses nothing from `Illuminate\Database`, the models, or the wall clock. The compiler lives in `Afzal\DynamicThrottle\Compiler`.
- **AC-20** L0 is process-wide, so it lives in a static property. `ManifestRepository::flushState()` clears it, and every test starts with it empty.

### Config
- **AC-21** New keys, merged by `ConfigMerger` (SPEC-005):
  - `manifest.refresh_seconds`, from `DYNAMIC_THROTTLE_MANIFEST_REFRESH`, default 2
  - `manifest.schedule`, default `true`

## Interfaces and files
- `src/Manifest/Manifest.php`: readonly value object: `version`, `rules` (a `list<CompiledRule>`), and `state` (`ManifestState`).
- `src/Manifest/CompiledRule.php`: readonly value object, with the enums of SPEC-005.
- `src/Manifest/ManifestState.php`: an enum with `Fresh`, `Stale` and `Missing`.
- `src/Manifest/ManifestCodec.php`: encodes and decodes the JSON strictly, and throws on any shape error.
- `src/Manifest/ManifestRepository.php`: `current(): Manifest` and `flushState()`.
- `src/Manifest/Contracts/ManifestRebuilder.php`: the repository schedules rebuilds through this interface, so it never depends on the compiler or the database.
- `src/Compiler/ManifestCompiler.php`: `compile(): CompileResult` (version, rule count, skipped rows). It implements `ManifestRebuilder`.
- `src/Compiler/RuleObserver.php`: after-commit (`ShouldHandleEventsAfterCommit`).
- `src/Compiler/ManifestCompileFailed.php`
- `src/Console/CompileCommand.php`: `dynamic-throttle:compile`.
- `src/Engine/KeyBuilder.php`: `manifest()`, `manifestLock()` and `manifestRebuild()`.
- `config/dynamic-throttle.php`: the `manifest` section.
- The service provider registers the observer, the command, the schedule, and the repository as a singleton.
- Tests:
  - `tests/Feature/Compiler/ManifestCompilerTest.php`: AC-1 to AC-9
  - `tests/Feature/Console/CompileCommandTest.php`: AC-10, AC-11
  - `tests/Feature/Manifest/ManifestRepositoryTest.php`: AC-12 to AC-18, AC-20
  - `tests/Unit/Manifest/ManifestCodecTest.php`: the shape checks behind AC-16
  - `tests/Unit/Engine/KeyBuilderTest.php`: the new keys
  - `tests/Arch/ArchTest.php`: AC-19
  - `tests/Unit/ConfigTest.php`: AC-21

## Out of scope
- The exemptions and runtime-settings sections of the manifest. They arrive with SPEC-009 and SPEC-013, and bump the manifest format.
- The pre-index by method and first path segment (SPEC-007).
- L1, APCu (open question 1).
- Health checks for a stale manifest or a scheduler that is not running (SPEC-016).

## Edge cases and failure modes
- **`cache:clear` or eviction.** The manifest goes missing:
  - Octane workers keep serving L0 until the rebuild.
  - FPM requests apply no rules until one of them rebuilds after its response, typically within one request, or the scheduled compile heals it (AC-14, AC-15).
- **Database down during a compile.** The compile throws, and the stored manifest stays as it was (AC-6). Rules keep applying from the cache.
- **Two servers compile at once.** The lock serializes them, and each reads the database inside the lock, so the last writer writes the latest data.
- **Clock skew between compiling servers.** The version is `max(previous + 1, now)`, so it still grows. After a flush, a server whose clock is behind could repeat an old version, but a worker would only miss the change if its L0 held exactly that number. The compile after the next change fixes it.
- **Propagation delay.** A change reaches Octane workers within `refresh_seconds`, and FPM requests at once (ADR-0007).
- **Large manifests.** 500 rules is ADR-0015's cap, about 150 KB of JSON. On FPM each limited request decodes it. The Phase 2 benchmark measures this, with 100 rules.
- **A rebuild on an FPM request** runs after the response is sent (`terminating`). The client never waits for it.

## End-to-end verification
- `composer test`, `composer analyse`
- `composer test:drivers`: the compiler on MySQL and PostgreSQL, through SPEC-005's schema tests
- In a Workbench app:
  1. Create a rule with `tinker`.
  2. Check that `php artisan dynamic-throttle:compile` prints the new version.
  3. Run `php artisan cache:clear`, then send a request: the rebuild runs after the response.

## Open questions
1. **L1 (APCu) now or later?** ADR-0007 makes it optional. Testing it needs `ext-apcu` in CI, and it only helps FPM servers that have APCu. Recommended: later, only if the Phase 2 benchmark shows manifest reads on FPM matter. This spec keeps the read path ready for it.
2. **Register the five-minute compile in the app's scheduler automatically?** With it, healing works out of the box, and `manifest.schedule` can turn it off. Without it, the docs ask users to add it, as Telescope does for pruning. Recommended: register it, since it changes no request.
3. **Rebuild after the response in the same process, or through a queued job?**
   - `terminating()` needs no queue worker, and runs after the response on both FPM and Octane.
   - A job would need a running worker, and would delay the heal.

   Recommended: `terminating()`.
4. **Keep `name` in the manifest?** It lets events, logs and `explain` name the rule without a database query, and costs a few bytes per rule. Recommended: keep `name`, and drop `description`.
