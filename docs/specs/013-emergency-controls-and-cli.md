# SPEC-013: Emergency controls and the CLI

- Status: Done
- ADRs: ADR-0011, ADR-0007, ADR-0015, ADR-0017, ADR-0020
- Roadmap phase: 3

## Problem
During an incident, an admin needs to lift or loosen every limit at once, or switch off one bad rule, within seconds and without a deploy, and needs that change to end by itself. Today the only switch is `DYNAMIC_THROTTLE_ENABLED`, which needs a config reload. Changing a rule needs code or a database client, and nothing shows what the servers apply. ADR-0011 decides runtime settings in the database, compiled into the manifest and changed from Artisan. ADR-0015 adds that the CLI can list, disable and bypass rules when the dashboard is unreachable.

## Behavior

### The settings (ADR-0011)
- **Two settings:** `bypass_all`, which is on or off, and `scale_factor`, from 0.1 to 10 with at most two decimals. Each can have an end time.
- **They live in `dynamic_throttle_settings`** (ADR-0020 point 7), one row per setting that is on, with its value and its optional end time. Turning a setting off deletes its row.
- **They are compiled into the manifest** with the rules and exemptions. The manifest's format goes from 2 to 3, so a server reads a manifest of format 2 as corrupt, and the manifest is rebuilt (SPEC-006). A setting whose end time has passed at compile time is left out.
- **Saving or deleting a setting compiles the manifest** after the transaction commits, as rules and exemptions do (ADR-0007).
- **A row that is not a valid setting** is skipped and reported, as an invalid rule is (SPEC-006), and the other setting still compiles.

### On the request path
- **Bypass.** While `bypass_all` is on, the middleware lets every request through untouched: no rule is matched or counted, no header is added, and no event is sent, as for a request no rule matches. The kill switch, the package's own routes and `except` are checked first, as today. Bypass needs only the manifest, which the middleware reads anyway.
- **Scale.** Each matched rule counts the request against `max(1, ceil(max_attempts × scale_factor))` (ADR-0011 point 5), dry-run rules too. The headers, the 429 and the `RateLimitWouldBlock` event's attempt show that limit.
  - The arithmetic is exact: the factor is held as a whole percentage, 150 for 1.5, and the limit is computed in integers. Floating point would make 100 × 1.1 into 111.
  - The counters are not touched, because the window does not change (ADR-0006). A request counted before the scale changed still counts after it.
- **End times are checked at read time,** with each request's clock (ADR-0011 point 3). A setting stops applying at its end time, even when no compile runs in between, from L0 and L1 as from the cache store.
- **A setting applies only through the manifest.** The request path never reads the settings table (CLAUDE.md's hard rule). Servers pick up a change within `manifest.refresh_seconds`, as they do a rule's.

### The commands
All are in the `dynamic-throttle:` namespace. They need the database, except `status`, which reads only the cache when the database is down.

| Command | What it does |
|---|---|
| `status` | Shows what servers apply, and checks the config |
| `bypass on\|off [--for=] [--forever] [--force]` | Turns `bypass_all` on, until the end time, or off |
| `scale <factor>\|off [--for=] [--forever] [--force]` | Sets `scale_factor`, until the end time, or turns it off |
| `list` | Lists the rules, with the ids the other commands take |
| `disable-rule <id> [--force]` and `enable-rule <id>` | Switches one rule off or on (ADR-0015 point 6) |
| `compile` | Unchanged (SPEC-006) |

**Every command that changes something:**
- writes the change, and one audit entry with it, in one transaction, retried after a deadlock, and once when another process inserted the same setting first;
- lets the model observer compile the manifest once, after the commit, as for any change (ADR-0007), and reports that compile (decision 10). When it fails, the change stays saved, and the command says so, without saying servers apply it, says that servers keep the previous manifest until a compile succeeds (the five-minute schedule retries it, when it runs), and exits 1;
- once compiled, prints what servers will apply and until when, and that they pick it up within `refresh_seconds`;
- in production, asks for confirmation before a dangerous change, as ADR-0015's guardrails list them: bypass on, a factor below 1, and disabling an enforcing rule. `--force` skips the question, as `migrate --force` does. Without a terminal and without `--force`, the command changes nothing and exits 1.
- A command that changes nothing, such as `bypass off` while bypass is off or has ended, says so, writes no audit entry, and exits 0.
- Names and messages from the database print as text: a rule's name can neither style the output, which could stop the command, nor drive the terminal.

**`--for`** takes a whole number and a unit: `90s`, `30m`, `2h` or `1d`, from 1 second to 365 days. The end time is the command's time plus that. **Without `--for`, a change ends after one hour** (decision 2). `--forever` sets no end time, and cannot be combined with `--for`.

**`status`** never writes, and never compiles or rebuilds anything. It shows:
- the kill switch, the driver and the store it uses;
- the manifest that servers read from the cache store: its version and how many rules and exemptions it holds, or that it is missing or cannot be read;
- the settings in that manifest, with their end time and the time left, or that a setting has ended;
- the settings in the database, when they differ from the manifest's, or there is no manifest: a change no compile has written yet;
- **exits 1 when the config is invalid** (ADR-0020 point 1): an unknown driver, an undefined store, an invalid prefix, or a Redis connection the config does not define, with the error. Otherwise it exits 0, even when the manifest is missing or the database is down. SPEC-016 adds its warnings to this output, and they do not change the exit code.

**`list`** shows the rules in the database, enabled or not, in the manifest's order (priority, then id): id, name, method, pattern, scope, limit per window, mode, failure policy, and whether each is enabled.

### The audit log (ADR-0015, decision 1)
- **`dynamic_throttle_audit_log`**, append-only: the model refuses updates and deletes. Each entry records the actor, the action, the target, the state before and after as JSON, the IP, the user agent and the time (ADR-0015 point 3).
- **The CLI's actor is `cli:<system user>`**, the user that runs the command. IP and user agent are empty.
- **Actions:** `settings.bypass`, `settings.scale`, `rule.disable` and `rule.enable`. `compile` changes no data and writes no entry.

## Acceptance criteria
Feature tests run on SQLite with the array cache store and Laravel's time travel. Request tests go through the middleware.

- **AC-1 (schema)** The migrations create `dynamic_throttle_settings` and `dynamic_throttle_audit_log` on the configured connection, and rolling back drops them.
- **AC-2 (compile)** With bypass on until T and the factor 2 with no end time, the compiled manifest holds both, with T as Unix seconds. A setting that ended before the compile is left out. With no setting, the manifest holds none.
- **AC-3 (invalid rows)** A row with an unknown name, a factor of 0.05, 11 or `abc`, or an end time that is not a date is skipped and reported, and the other setting compiles.
- **AC-4 (after commit)** Saving or deleting a setting compiles the manifest once, after the transaction commits, and not at all when it rolls back.
- **AC-5 (bypass)** With a rule of limit 1 on `api/x`, and bypass on: three requests get 200 with no `X-RateLimit-*` header, no counter is written, and no event is sent. After `bypass off`, the first request counts as the first.
- **AC-6 (scale)** With the factor 2 on a rule of limit 10, the 20th request gets 200 and the 21st gets the 429, and `X-RateLimit-Limit` is 20. A rule of limit 3 with the factor 0.5 allows 2 requests, a rule of limit 1 with the factor 0.1 allows 1, and a rule of limit 100 with the factor 1.1 allows 110.
- **AC-7 (counters kept)** On a rule of limit 10, five requests counted with no scale, then the factor 2: the next request gets `X-RateLimit-Remaining` 14.
- **AC-8 (dry-run)** A dry-run rule of limit 2 with the factor 2 sends its first `RateLimitWouldBlock` on the 5th request.
- **AC-9 (end times)** With bypass on until T, a request at T − 1 s passes untouched, and a request at T is counted, with no compile in between. The same holds for the factor.
- **AC-10 (L0 and L1)** A manifest held in the process and one from L1 apply the settings, and stop applying them at their end time.
- **AC-11 (no database)** With bypass on and with the factor 2, a counted request runs no database query.
- **AC-12 (bypass command)** `bypass on --for=30m` saves bypass until now + 30 minutes, compiles, prints the end time, and exits 0. `bypass off` deletes it. Requests follow both. Without `--for`, bypass ends after one hour, and with `--forever` it has no end time. `--for` with `--forever` prints why, writes nothing and exits 1.
- **AC-13 (scale command)** `scale 2 --for=2h` and `scale 0.5 --for=2h` save the factor, `scale 2` alone saves it for one hour, and `scale off` deletes it. `scale 0.05`, `scale 11`, `scale abc` and `scale 1.234` print why and exit 1, writing nothing.
- **AC-14 (durations)** `--for` accepts `90s`, `30m`, `2h`, `1d` and `365d`. It rejects `0m`, `-5m`, `5`, `5w`, `366d` and `soon`, printing why and writing nothing.
- **AC-15 (confirmation)** In production, `bypass on`, `scale 0.5` and `disable-rule` on an enforcing rule ask first. Answering no writes nothing and exits 1. `--force` asks nothing. Without a terminal and without `--force`, nothing is written and the command exits 1. Outside production nothing is asked, and `scale 2` and `enable-rule` never ask.
- **AC-16 (rules)** `disable-rule 3` disables rule 3 and compiles, and requests stop matching it. `enable-rule 3` brings it back. An unknown id prints why and exits 1. Disabling a disabled rule says so, writes nothing and exits 0.
- **AC-17 (list)** `list` shows every rule, disabled ones too, in priority then id order, with the columns above. With no rule, it says so.
- **AC-18 (status)** `status` shows the kill switch, the driver, the manifest's version and counts, and each setting with its end time and the time left, and exits 0. A missing manifest, and a setting saved but not compiled, are shown, and it still exits 0.
- **AC-19 (status and the config)** With an unknown driver, an undefined store or an invalid prefix, `status` prints the error and exits 1 (ADR-0020's verification of point 1).
- **AC-20 (status writes nothing)** `status` with a missing manifest writes nothing to the cache or the database, and compiles nothing.
- **AC-21 (a compile that fails)** With the cache store down, `bypass on --for=30m` saves the setting, says that servers keep the previous manifest until a compile succeeds, and exits 1.
- **AC-22 (the database is down)** With the database unreachable, `bypass on --for=30m` prints the error and exits 1. Requests keep the settings of the manifest compiled last (ADR-0011's fallback).
- **AC-23 (audit)** Each command that changes something writes exactly one audit entry, in the change's transaction: actor `cli:<system user>`, action, target, before and after. A command that changes nothing writes none. Updating or deleting an entry through the model throws.
- **AC-24 (the manifest's settings)** The codec rejects format 2, and a settings section with an unknown setting, a factor outside 0.1 to 10 or with more than two decimals, or an end time that is not an integer. L1 keeps the settings, and an L1 entry whose settings are invalid is a miss (SPEC-028 AC-9).
- **AC-25 (hard rules)** The arch tests hold: no database and no wall clock on the request path, and keys only from `KeyBuilder`.
- **AC-26 (times in UTC)** (decided at the Phase 3 review, 2026-10-13) The settings table and the audit log store their times in UTC, whatever the app's time zone, so a daylight-saving change cannot move a setting's end time by the repeated hour. Tested by `tests/Feature/Models/TimesInUtcTest.php`.

## Interfaces and files
- `database/migrations/2026_10_01_000001_create_dynamic_throttle_settings_table.php`: `id`, `name` (unique), `value`, `expires_at` (a datetime, as exemptions use), timestamps.
- `database/migrations/2026_10_01_000002_create_dynamic_throttle_audit_log_table.php`: `id`, `actor`, `action`, `target`, `before` and `after` (JSON), `ip`, `user_agent`, `created_at`.
- `src/Models/Setting.php` and `src/Enums/SettingName.php` (`bypass_all`, `scale_factor`), observed by `ManifestObserver`.
- `src/Models/Concerns/UsesPackageConnection.php` (new): the package's connection, which `Rule`, `Exemption`, `Setting` and `AuditEntry` share.
- `src/Models/AuditEntry.php` and `src/Enums/AuditAction.php`.
- `src/Manifest/ManifestSettings.php` (new, readonly): the settings as the manifest holds them. `bypasses(int $now): bool`, and `scalePercent(int $now): int`, the factor as a percentage, 100 for none, for a time in Unix seconds. `at(int $now)` drops the settings that have ended.
- `src/Manifest/Manifest.php`, `ManifestCodec.php` (format 3) and `ManifestSnapshot.php` (L1): the settings.
- `src/Compiler/ManifestCompiler.php`: reads the settings. `settings()` gives them, and the invalid rows, without compiling, for `status`. `dynamic-throttle:compile` prints what it printed.
- `src/Engine/Stacking/RuleEvaluator.php`: `evaluate()` takes the factor as a percentage, and plans each attempt with the scaled limit.
- `src/Engine/Stacking/Scale.php` (new): `Scale::limit(int $maxAttempts, int $percent): int`, the scaled limit in integers.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: bypass, and the factor, from the manifest and the clock.
- `src/Console/StatusCommand.php`, `BypassCommand.php`, `ScaleCommand.php`, `ListCommand.php`, `DisableRuleCommand.php` and `EnableRuleCommand.php`. The four that change something extend `ChangeCommand`, which holds `--for`, the confirmation and the messages. `CliActor` names the system user.
- `src/Support/LimiterStoreFactory.php`: `describe()`, the driver it picks and where it counts, for `status`.
- `src/Support/EmergencyControls.php` (new, internal): each change with its audit entry, in one transaction, for the commands now and the JSON API (SPEC-017) and the dashboard (SPEC-021) later. It returns a `ChangeOutcome`: unchanged, compiled, failed to compile, or pending in a surrounding transaction. It throws `TableMissing` before writing when a table is missing.
- `src/Compiler/LastCompile.php` (new, internal, scoped): the outcome of the observer's last compile, which `ManifestObserver` records and `EmergencyControls` reads back.
- `src/Console/Printable.php` (new, internal): text from the database or an exception, as the commands print it.
- `src/Console/Duration.php` and `src/Support/ScaleFactor.php` (new, internal): `--for` and the factor, parsed with the messages above. The compiler parses the stored factor with `ScaleFactor` too.

## Out of scope
- Changing the settings from the JSON API (SPEC-017) and the dashboard (SPEC-021).
- Reading the audit log, and the entries of the API and the dashboard (SPEC-019, SPEC-025).
- A start time for a setting, such as "double limits on Friday from 18:00" (ADR-0011's revisit, the roadmap's later list).
- Scaling one rule, or one server.
- The health warnings (SPEC-016), and `explain` (SPEC-014).
- Creating, editing or deleting rules from the CLI. `disable-rule` is the recovery path ADR-0015 point 6 asks for.

## Edge cases and failure modes
- **Bypass and the factor together:** bypass wins, and nothing is counted.
- **The servers' clocks differ:** each server ends a setting by its own clock, so they disagree by their skew. NTP keeps that to milliseconds.
- **The end time is exact:** a setting applies while the time is before its end, and not at it.
- **Time zones:** `expires_at`, the timestamps and the audit log's `created_at` are stored in UTC, whatever the app's time zone, and the compiler reads them as UTC (AC-26, as SPEC-009 AC-24).
  - **Daylight saving time** (found by the review): when the clocks go back, an hour repeats, and an end time in it reads back as its later occurrence, so a setting can last up to an hour longer. Exemptions share it. Storing the package's times in UTC would fix both, and is proposed for before 0.1.0 (the roadmap).
- **A huge limit:** a scaled limit beyond PHP's largest integer stays at that integer.
- **The settings table is missing,** because the app has not run the new migration: the compiler compiles no settings and reports it, as for exemptions (SPEC-009). The change commands print how to publish and run the migration, and exit 1.
- **Two admins at once:** the last write wins, and each writes its own audit entry. A deadlock is retried, and so is an insert another process made first, as MySQL and PostgreSQL report two concurrent first writes. A rule is read again, locked, in the transaction, so the same change is never made and audited twice. The compile lock orders the compiles (SPEC-006).
- **A surrounding transaction:** a change made inside one compiles only when it commits, and not at all when it rolls back, since the observer compiles after the commit.
- **A rolling deploy** (found by the review): servers of format 2 and of format 3 share the cache store, and each reads the other's manifest as corrupt and rebuilds it. Putting the format in the manifest's key would keep them apart, and is proposed for before 0.1.0 (the roadmap).
- **A stale manifest** (ADR-0010) keeps its settings until their end time, so a bypass set before an outage keeps working through it.
- **The kill switch** is unchanged. It is the last resort when both the database and the cache are down (ADR-0011 point 4).
- **The system user cannot be read,** without the `posix` extension: the actor falls back to the `USER` environment variable, then to `USERNAME`, which Windows sets instead (issue #96), then to `cli:unknown`. Through sudo, it also names the user who ran sudo: `cli:root (sudo by alice)`.

## End-to-end verification
- `composer test -- --filter=SPEC-013` and the files below, `composer test:apcu` for AC-10, and `composer analyse`, `format:check` and `refactor:check`
- `composer test:mutate`, since `src/Engine` changes (`RuleEvaluator`)
- `composer bench`: `MiddlewareBench`, since every request now checks the settings
- the review subagent: the engine, the public API (commands and schema) and the audit log are all in this spec

Done on `030412c`:
- `composer test`: 1,270 passed. `composer test:apcu`: 60 passed. `composer test:drivers`: 478 passed, with the settings and audit tables on MySQL and PostgreSQL. `composer analyse`, `format:check` and `refactor:check` are clean.
- `composer test:mutate`: 98.12%, where the gate is 85%. Nothing survives in `Scale` or `RuleEvaluator`; the survivors predate this spec, and the review's fixes did not touch `src/Engine`.
- `composer bench`: on the array store, a request costs 1 to 21 µs more at p50, of which the added work alone is about 4 µs. PHP-FPM with APCu stays under the 0.5 ms target, at 429 to 437 µs at p95 (`benchmarks/results/phase-3-emergency-controls.md`).
- The review subagent checked `Scale::limit()` against bcmath on 2.2 million cases, and found no high-severity defect. Its findings are fixed test-first, in `63053f4`, then `5aa0d82`:
  - names print as text, so a name with console styles can no longer stop `list` or `disable-rule`;
  - a failed compile never says servers apply the change;
  - `status` reports an undefined Redis connection, and shows saved settings with the manifest missing;
  - the observer compiles once after the commit (decision 10), so a surrounding transaction that rolls back changes nothing and no model event is silenced;
  - concurrent changes are retried, and a rule is read again in the transaction;
  - an ended setting turned off is a no-op; `--for` needs a duration; `enable-rule` takes `--force`; the commands say when the database is down; the actor names the sudo user; the audit log keeps JSON as written and long user agents.
- Two findings are proposals for the maintainer before 0.1.0 (the roadmap): the manifest's key naming its format, and the package's times in UTC.
- Three test-only commits followed the red steps. `602b2da` runs a pending command before reading what it wrote, which an AC-15 test did not. `58b2859` and `7023d41` are Rector's style changes. None changes what a test asserts.
- Not done: the end time is computed before the confirmation in production, so a slow answer to `--for=30s` could save an end time already past. The test harness answers prompts at once, so no test can show it.

## Open questions
None left. The maintainer answered both on 2026-10-01: decisions 1 and 2.

## Decisions
Recorded as recommended (ADR-0027), except the first two, which the maintainer decided on 2026-10-01. The maintainer can veto the others in the PR.
1. **The audit log ships with this spec, and the CLI writes to it** (decided by the maintainer). ADR-0011 point 6 writes every change to the audit log, but SPEC-005 had placed its table in SPEC-019, in Phase 4, after 0.1.0. 0.1.0 is CLI-only and goes to production, and "who bypassed everything?" is the first question an incident asks. SPEC-019 adds the JSON API's and the dashboard's entries, and reading them. ADR-0011's and ADR-0015's decision records and the roadmap note it.
   - Rejected: SPEC-019 adding the CLI's entries too, so 0.1.0 would ship without an audit; and Laravel's log until SPEC-019, which is neither append-only nor queryable.
2. **A change without `--for` ends after one hour** (decided by the maintainer). `--forever` sets no end time. ADR-0011 keeps the end time optional, and says that "expiry turns 'remember to undo this' into a default": a forgotten bypass ends within the hour.
   - Rejected: requiring `--for` or `--forever`, which costs a retry in an incident; and no end time unless `--for` is given, which leaves a forgotten bypass on.
3. **One row per setting that is on**, named by a backed enum, rather than one row with a column per setting. Each setting keeps its own end time, and a new setting needs no migration (ADR-0007's string columns with backed enums).
4. **The factor has at most two decimals,** held as a whole percentage, so the limit is computed exactly in integers.
5. **Dry-run rules are scaled too,** so they keep showing what the enforcing rule would do (ADR-0012).
6. **Rules are switched off and on with `disable-rule` and `enable-rule`,** by id, since names are not unique. `disable` alone would read as switching the package off.
7. **Confirmation follows ADR-0015's list of dangerous actions, in production only,** with `--force`, as Laravel's own commands do. An incident script passes `--force`.
8. **`status` exits 1 only for an invalid config** (ADR-0020 point 1). A missing manifest or an unreachable database is shown, but does not fail a deploy script.
9. **`--for` stops at 365 days,** so a typo cannot overflow the end time.
10. **A change and its audit entry commit together, and the model observer compiles once, after the commit** (revised after the review). The observer records the outcome in a scoped `LastCompile`, which the change reads back. The draft had `EmergencyControls` compile itself with the models' events silenced, which also silenced the app's listeners, compiled inside a surrounding transaction that could still roll back, and went against ADR-0007's "controllers never compile directly".
