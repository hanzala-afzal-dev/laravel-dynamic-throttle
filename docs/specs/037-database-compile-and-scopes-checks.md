# SPEC-037: Say when the database, the compile or a custom scope keeps rules from applying

- Status: Done
- Amends: [SPEC-016](016-health-checks.md) AC-21, whose arch rule kept every check away from the database
- ADRs: ADR-0007, ADR-0009, ADR-0010, ADR-0017, ADR-0020
- Roadmap phase: after 0.2.0 (issue #205)

## Problem

`dynamic-throttle:health` looks for setup mistakes that switch limits off without a sound (SPEC-016). The 0.2.0 audit found four it misses, on `develop` (f528e77):

- **The database cannot be reached.** The report had 2 warnings, about the store and the metrics, and no error, with exit 0. `dynamic-throttle:status` does print "The database cannot be read". Requests still apply the manifest in the cache, but no rule can be saved or compiled, and the commands that change limits fail.
- **The package's tables are missing.** With the settings, metrics, audit log and links tables dropped, and links on, no warning named a table.
- **The compile skips enabled rules as invalid.** `dynamic-throttle:compile` printed "2 rules, 3 skipped", and the `manifest` check said ok, "2 rules". A rule saved through the model with `window_seconds` 0 is enabled, and never applies.
- **A `custom` rule names a scope that nobody registered.** Each request it matches fails to find the client: the error is reported, and the request goes through, or answers 503 on a fail-closed rule.

## Behavior

Three new checks, in `dynamic-throttle:health`, on the Overview's health card and on the Settings page:

- **`database`:** the package's connection answers, and each of its tables exists.
- **`compile`:** the rows the compile skips as invalid: enabled rules, the exemptions it would compile, and settings. Each with the compile's own reason.
- **`scopes`:** custom scopes that the manifest's rules and exemptions use, and that have no registered resolver.

## Decisions

1. **The ids are `database`, `compile` and `scopes`, listed around `manifest`: `database`, `manifest`, `compile`, `scopes`.** The database comes before the manifest it compiles to, and the two checks of what the manifest applies come after it. `skipped` was the issue's name for `compile`, but `skipped` is already a check status, and "skipped SKIPPED" would read badly.
2. **`database` errs when the database cannot be reached, or the rules table is missing.** Then no rule can be saved, and every compile fails, so servers keep the last manifest until it is evicted. A missing table of the others is a warning, since rules still compile and apply without it. It names each table with what does not work without it:
   - exemptions: the manifest has no exemptions;
   - settings: bypass and scale cannot be turned on;
   - audit log: the dashboard and the commands can change nothing, since each change writes an audit entry;
   - hourly metrics: the traffic numbers are never stored, and only while telemetry is on;
   - links: links can be neither made nor used, and only while links are on.
3. **Laravel's own code asks the database,** as the maintainer asked in chat: the connection's `getPdo()`, which connects or throws, then `Schema::hasTable()` for each table. `getPdo()`'s error is the driver's own, such as `SQLSTATE[HY000] [2002] Connection refused`, without the host and the SQL that a query's error adds. The message gives it with the connection's name.
4. **`compile` is a warning,** as `unreached` is: the other rules still apply, and `--strict` catches it for deploys. It reads and checks the rows through `ManifestRows`, which the compiler uses too, so their reasons match. `ManifestRows` only reads: the compiler writes the manifest and reports the invalid rows. So the check never compiles, writes or reports, and never uses the compiler. It is skipped when the database cannot be read, since `database` already errs. A missing exemptions or settings table skips nothing here, since `database` names it.
5. **`compile` names what the compile would skip, not what the manifest lacks.** Comparing the database with the manifest would also name every rule changed since the last compile, which is normal for up to `manifest.refresh_seconds`.
6. **`scopes` reads the manifest, which is what requests apply.** It is skipped without one, as `unreached` is. It errs when an enforcing, fail-closed rule uses a scope that is not registered, since each request that rule matches answers 503. Otherwise it is a warning. A dry-run rule never answers 503 (ADR-0012).
7. **`scopes` counts only the resolvers the command's process registered.** Apps register them in a service provider's `boot()`, which runs for Artisan too. An app that registers some only for HTTP requests sees a warning on the command line. The dashboard's Settings page runs the checks in the web server, where they are registered.
8. **SPEC-016 AC-21's arch rule now allows the two checks that read the database,** `database` and `compile`, and no other. Three new rules keep it narrow: `database` uses no model, `compile` reads only through `ManifestRows`, and `ManifestRows` never writes the cache, compiles or reports, and only the compiler and `compile` use it. CLAUDE.md says never to weaken an arch test without asking: the PR asks the maintainer to confirm this change.
9. **Exact messages:**
   - `database` ok: `The database answers on connection {name}, and the package's tables exist.`
   - `database` error, unreachable: `The database cannot be reached on connection {name}, so no rule can be saved or compiled, and servers keep the last manifest: {error}`
   - `database`, missing tables: one sentence per table, `The {table} table does not exist, so {what does not work}.`, then `Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, then run php artisan migrate.`
   - `compile` ok: `Every enabled rule, and every exemption and setting the manifest would hold, compiles.`
   - `compile` warning: `The compile skips {n} invalid {row|rows}, so {it applies|they apply} nowhere:`, the compile's messages, the first ten, such as `Rule 4 was skipped: window_seconds must be from 1 to 86400, got 0.`, then `And {n} more.` when there are more, then `Fix or delete {it|them}, then run php artisan dynamic-throttle:compile.`
   - `compile` skipped: `The database cannot be read: see the database check.`
   - `scopes` ok, none used: `No rule or exemption uses a custom scope.`
   - `scopes` ok: `Every custom scope the rules and exemptions use is registered: {names}.`
   - `scopes` warning or error: `{n} custom {scope is|scopes are} not registered: {name}, for {rule 4, Per tenant; exemption 7}.` for each, then `A rule that uses one fails to find the client on every request it matches: the error is reported, and the request goes through, or answers 503 on a fail-closed rule. An exemption that uses one exempts nobody. Register them with DynamicThrottle::scope() in a service provider's boot().`
   - `scopes` skipped: the manifest's problem, as `unreached` says it.

## Acceptance criteria

- **AC-1** With every table in place, `database` is ok with its message, naming the connection.
- **AC-2** With the package's connection refused, `database` errs with the unreachable message and the root cause's error, `compile` is skipped with its message, and the command exits 1.
- **AC-3** Without the rules table, `database` errs, naming it, and `compile` is skipped.
- **AC-4** Without the exemptions, settings or audit log table, `database` warns, naming each with what it stops. Without the hourly metrics table, it warns only while telemetry is on. Without the links table, it warns only while links are on.
- **AC-5** `compile` warns about an enabled rule the compile skips, such as one with `window_seconds` 0 or a `header` scope without a header, with the compile's message. A disabled invalid rule is not named.
- **AC-6** `compile` warns about an invalid exemption of an enabled rule, and an invalid global one, but not about one of a disabled rule. It warns about an invalid settings row.
- **AC-7** With 12 invalid rules, `compile` names the first ten, then "And 2 more.", and says "12 invalid rows". It writes nothing, compiles nothing and reports nothing.
- **AC-8** `scopes` warns about a custom rule and a custom exemption whose scope has no resolver, naming the scope and what uses it. It is ok once the scope is registered, by a closure or by a `ScopeResolver` class.
- **AC-9** `scopes` errs when an enforcing, fail-closed rule uses an unregistered scope, and warns for a dry-run, fail-closed one.
- **AC-10** Without a custom scope, `scopes` is ok with its message. Without a manifest, it is skipped with the manifest's problem.
- **AC-11** The report lists `database`, `manifest`, `compile` and `scopes` in that order, after the route checks. `GET api/health` includes them, and the Settings page shows what each looks for. The command still writes nothing (SPEC-016 AC-3).

## Interfaces and files

- `src/Support/Health/Checks/DatabaseCheck.php`, `CompileCheck.php` and `ScopesCheck.php`, listed in `HealthChecks`.
- `src/Compiler/ManifestRows.php`: the compiler's reading and checking of the rows, moved out of `ManifestCompiler`, which compiles from it and reports the same invalid rows as before. `skipped()` gives them, for `compile`.
- `tests/Arch/ArchTest.php`: decision 8.
- `resources/dashboard/lib/settings-page.ts`: what each check looks for. The Build dist workflow rebuilds `dist/`.
- `docs/guide/health.md`, `CHANGELOG.md`.

No config key, command, event or schema changes. New check ids are not a breaking change (ADR-0017), but a deploy script that runs `--strict` may stop on their warnings, so the release's first paragraph says so, as 0.1.0's did for `unreached`.

## Out of scope

- A check that the scheduled compile succeeds: `manifest` already warns when the manifest is 10 minutes old.
- Validating a rule when the model saves it: issue #212.
- Comparing the manifest with the database (decision 5).

## Edge cases and failure modes

- **The database answers, but slowly:** each check reads it once, through the connection's own timeout. The dashboard reads the health report once a minute while a page shows it.
- **Many invalid rows:** `compile` reads every enabled rule and every exemption, as a compile does. With 20,000 expired exemptions, a compile took 287 ms in the 0.2.0 audit. The check runs only for the command and the dashboard's health endpoint, never for a request.
- **A table on another connection:** every table is checked on the package's connection, `dynamic-throttle.database.connection`, as the migrations create them.
- **A manifest that is not readable:** `scopes` is skipped with the manifest's problem, and `manifest` errs.

## End-to-end verification

- `composer test -- --filter=HealthDatabase`, `--filter=HealthCompile` and `--filter=HealthScopes`, then `composer test`, `composer analyse` and `composer test:drivers`.
- `npm run test`.
- In the Workbench app: drop a table, save a rule with `window_seconds` 0 through tinker, and add a `custom` rule for an unregistered scope. `php artisan dynamic-throttle:health` names all three.

## Open questions

None.

## Notes from implementation

- `tests/Support/Health.php` lists the three ids. So the two tests that compare the report with that list failed with the others at the red step.
- Decision 1's order changed after the spec's first commit: `database` was to follow `config`. That shifted the positions that two tests read from the list, so `database` comes before `manifest` instead.
- The first version read the database through the models and the compiler. SPEC-016's arch rules refused it, and the maintainer asked for Laravel's own code for the database. So `database` asks Laravel's connection and schema builder, `compile` reads through `ManifestRows`, and decision 8 narrows the arch rule.
- A guard test drops each table the migrations create, in turn, and expects `database` to name it, so a new table cannot be forgotten.
