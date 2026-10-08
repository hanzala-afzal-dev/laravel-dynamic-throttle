# SPEC-016: Health checks

- Status: In progress
- ADRs: ADR-0004, ADR-0005, ADR-0007, ADR-0008, ADR-0009, ADR-0010, ADR-0013, ADR-0017, ADR-0020, ADR-0022, ADR-0024, ADR-0025
- Roadmap phase: 3

## Problem
Most of the package's failure modes are silent by design: requests go through when something is wrong (ADR-0010, ADR-0020 point 1). So a misconfiguration can disable limits for weeks. Examples: counters per server on the `file` store; user rules that only ever see guests because authentication runs after them; a scheduler that never flushes metrics; a load balancer's IP that every client shares. ADRs 0004, 0005, 0007, 0008, 0009, 0010, 0013, 0020, 0022, 0024 and 0025, and SPECs 002, 006, 009 and 010, each leave a check to this spec. None exists.

## Behavior
**`php artisan dynamic-throttle:health`** runs every check and prints one line per check: its status, `ok`, `warning`, `error` or `skipped`, its id, and what it found. For a problem, the message says what to do (decision 2).
- It exits 1 when a check errs, and 0 otherwise. With `--strict`, a warning exits 1 too.
- `--json` prints the same report as one JSON object, for SPEC-017's endpoint.
- It changes nothing: no cache, Redis or database write, no compile and no rebuild.
- A check that throws is that check's error, and the others still run.

**The checks, in this order:**
- **Configuration and storage**, from the config:
  - `config`:
    - an error when the counter driver cannot be built: an unknown `driver`, an undefined `store`, an invalid `prefix`, or a Redis connection that `database.redis` lacks, as `dynamic-throttle:status` reports them (ADR-0020 point 1);
    - a warning when the kill switch is off;
    - a warning for each value the package replaces with its default, such as a `telemetry.sample_rate` of 7, or of `'0.5'`, a string. It names the value used (decision 14);
    - a warning for a group in `middleware.auto_attach` that the app does not define, and for an entry of `middleware.after` that is neither a middleware alias nor a class. Both do nothing (SPEC-010).
  - `store`: what the counter store guarantees (ADR-0005 point 4, SPEC-002):
    - ok: the Redis driver, and the cache driver on a `redis`, `memcached` or `dynamodb` store;
    - a warning for the `database` store, which adds database writes to every limited request. On SQLite, most attempts also fail under contention, and fail open;
    - a warning for the `file`, `apc` and `octane` stores, which count per server, so limits multiply by the servers. `file` and `apc` also lose counts under concurrency;
    - a warning for the `array` store, which is for tests only;
    - an error for the `null` store, which counts nothing;
    - ok for a store of any other driver, saying that the package's tests do not cover it (decision 15).
  - `cache-clear`: the Redis driver keeps the counters in a Redis database that a cache store also uses, so `php artisan cache:clear` wipes every limit (ADR-0020 point 2).
  - `app-key`: without an `APP_KEY`, identities are hashed without a key (ADR-0024).
- **Routes**, from the router, as `explain` reads them (SPEC-014):
  - `attached`: `dynamic.throttle` runs on no route and is not global middleware, so no rule applies (decision 13).
  - `except`: an installed package's path that `except` does not cover, so a rule can limit it (ADR-0020 point 3). Checked: Horizon, Telescope, Pulse and Nova by their `path` config, Nova's `nova-api`, and Sanctum's `csrf-cookie` by `sanctum.prefix`.
  - `two-limiters`: routes that run both Laravel's `throttle` and `dynamic.throttle`, which both write the rate-limit headers (ADR-0020 point 9).
  - `middleware-order`: routes where authentication runs after `dynamic.throttle`, so user rules count guests there (ADR-0004 point 6). Checked when a user rule exists and `scopes.user.guards` is not set.
- **The counter store's state:**
  - `manifest`: the compiled manifest is in the cache store and can be read. Without one, no rule applies (ADR-0010 point 3).
    - A warning when the scheduled compile has not rewritten it for 10 minutes, with `manifest.schedule` on: the scheduler is not running, or the compile fails (SPEC-006, decision 11).
    - A warning above 500 rules (ADR-0007's fallback) or 1,000 exemptions (SPEC-009 decision 4), which every load of the manifest pays for.
  - `metrics`: with telemetry on, the last minute flushed is at most 10 minutes old, or the scheduler is not running (ADR-0013's fallback).
- **What the rules saw in the last hour**, from telemetry (decision 1):
  - `guests`: a user rule that counted mostly guests, or let them pass as a `skip` rule. That usually means authentication that the route check cannot see, such as a middleware the package does not recognize (ADR-0004 point 6, ADR-0022).
  - `dominant-ip`: one client IP sent most of the recorded requests. That is often a load balancer's or a proxy's IP, because the trusted proxies are not set (ADR-0009). On the Redis driver only.
  - `ignored`: rules ignored because more than `matching.max_rules_per_request` rules of their mode matched a request (ADR-0008, ADR-0025).

**Telemetry records what the last three read** (decision 1), after the response, in the same call per request as SPEC-015's numbers. A request is recorded when it matched a rule that applied, skipped it or ignored it.
- Per rule and minute, three more numbers:
  - `guests`: the requests a user rule counted without a user, under `guest:<ip>`;
  - `skipped`: the requests a rule let pass because they had no identity for it (ADR-0022, decision 12);
  - `ignored`: the requests a rule was ignored for, over the cap (ADR-0025).
- On Redis, per hour:
  - each recorded request's client, by the identity the `ip` scope gives it, in a sorted set kept to the top 100, as the offenders are;
  - and how many requests were recorded.
- Each is weighed by the sample, as hits are, and expires 2 hours after it is first written. The flush leaves them in the store, and the hourly table does not change (decision 4).

## Acceptance criteria
Feature tests run on SQLite with the array cache store and the clock frozen. The Redis parts run against Redis and Valkey with phpredis and predis.

**The command**
- **AC-1 (output)** With every check passing, the command prints one line per check, in the order above: the status in capitals, the check's id and its message. Then it prints how many checks warned and erred, and exits 0. `--json` prints `{"status": "ok", "checks": [{"check": "config", "status": "ok", "message": "…"}, …]}` with the same checks in the same order, in ASCII only, as explain's JSON is. Text from the app, such as a rule's name or a route's URI, is printed as explain prints it.
- **AC-2 (exit codes)** A check that errs makes the status `error` and the exit code 1. A check that warns makes the status `warning` and the exit code 0, or 1 with `--strict`. `skipped` counts as ok.
- **AC-3 (read-only)** The command writes nothing: the cache store's keys, Redis's keys and the database's rows are the same before and after it. A check that throws is reported as an error with the exception's message, and every other check still runs.

**Configuration and storage**
- **AC-4 (config)**
  - An unknown `driver`, an undefined `store`, an invalid `prefix`, or a Redis connection that `database.redis` lacks: an error with `status`'s message. `store` and `cache-clear` are then skipped.
  - `enabled` false: a warning that the middleware does nothing.
  - `telemetry.sample_rate` 7, or `'0.5'`; `matching.max_rules_per_request` 0; `manifest.refresh_seconds` -1; `failure.cooldown_seconds` `'ten'`; `telemetry.retention_hours` 2,000,000; `scopes.ip.ipv6_prefix` 129; `scopes.user.guards` `'web'`: a warning that names the key, its value and the value used.
  - `middleware.auto_attach` `['api', 'apii']` where the app defines `api`: a warning that names `apii`. `middleware.after` `['jwt.auth']` where no such alias or class exists: a warning that names it.
  - The defaults: ok.
- **AC-5 (store)**
  - Ok: the Redis driver, and the cache driver on a `redis`, `memcached` or `dynamodb` store.
  - Warnings: a `database` store, about the database writes, and on SQLite about the failures under contention too; a `file` or `apc` store, about the servers and the lost counts; an `octane` store, about the servers; an `array` store, for tests only.
  - An error: a `null` store.
  - Ok, saying the tests do not cover it: a store of another driver.
- **AC-6 (cache-clear)** The Redis driver on the connection of a `redis` cache store, or on another connection to the same host, port and database, also when it is given by `url`: a warning that names the store whose `cache:clear` flushes the counters, and recommends a dedicated connection. A connection to another database: ok. The cache driver: ok.
- **AC-7 (app-key)** An empty `app.key`: a warning. A key: ok.

**Routes**
- **AC-8 (attached)** No route runs `dynamic.throttle` and it is not global middleware: a warning that says to attach it, with `middleware.auto_attach`. A route that runs it, or the global middleware: ok.
- **AC-9 (except)** `horizon.path` set to `admin/horizon`, which `except` does not cover: a warning that names Horizon, its path, and the two patterns to add. The default paths with the default `except`: ok. An `except` with `admin/*`: ok. A package whose config is absent is not checked.
- **AC-10 (two-limiters)** A route with `throttle:60,1` and `dynamic.throttle`: a warning that names its methods and URI. Twelve such routes: the first ten, then "and 2 more". A route with `throttle` alone, or one under `except`: ok.
- **AC-11 (middleware-order)**
  - With a user rule in the manifest and `dynamic.throttle` as global middleware, a route with `auth`: a warning that names the route.
  - `dynamic.throttle` in a group with `auth`: ok, since the priority list runs `auth` first.
  - Without a user rule: ok, "no user rule". With `scopes.user.guards` set: ok. Without a manifest: skipped.

**The counter store's state**
- **AC-12 (manifest)**
  - A manifest compiled 3 minutes ago: ok, with its version, its rules and exemptions, and when it was compiled.
  - None in the store: an error that says to run `dynamic-throttle:compile`. A store that throws, or a manifest that cannot be decoded: an error.
  - A manifest compiled 11 minutes ago, with `manifest.schedule` on: a warning that the scheduler is not running, or the compile fails. 10 minutes ago, or with `manifest.schedule` off: ok.
  - 501 rules, or 1,001 exemptions: a warning.
- **AC-13 (metrics)** At 11:01:30:
  - flushed through 10:51: ok;
  - flushed through 10:50: a warning that names the minute and "11 minutes ago";
  - nothing flushed in the store: a warning that the metrics have not been flushed in the last 2 hours, and to check the scheduler;
  - `telemetry.enabled` false: skipped;
  - a store that throws: an error.

**What the rules saw**
- **AC-14 (recording)** A user rule that counts guests under their IP, a user `skip` rule in dry run, and an IP rule, with a cap of 1. A guest's request, then a user's, give in the minute:
  - the first rule hits 2 and guests 1;
  - the second hits 1 and skipped 1;
  - the third ignored 2.

  A sampled request adds its weight to each. A header rule that skips a request without the header records skipped 1, and no guest. A user rule whose scope fails records nothing.
- **AC-15 (traffic, Redis)**
  - Two requests from 203.0.113.7 and one from 198.51.100.1 make the hour's requests 3, and its top clients the hashes of their `ip` identities, with 2 and 1.
  - Two IPv6 clients of one /64 count as one client, as the `ip` scope groups them.
  - A request whose client IP cannot be read is recorded without a client, and the hour's traffic leaves it out.
  - It is the same one script call per request.
  - The keys expire 7,200 seconds after they are first written.
  - The cache driver records neither, and reads null.
- **AC-16 (guests)** In the last 60 complete minutes:
  - a user rule with 150 matched requests, 90 of them guests: a warning that names the rule and the share;
  - a user `skip` rule that let 120 of 200 requests pass: a warning;
  - 50 of 100: a warning, at both thresholds;
  - 40 of 150: ok; 80 of 99: ok, too few to tell; numbers from before the 60 minutes, or from the minute that is not complete, do not count;
  - an IP rule is never checked.
- **AC-17 (dominant-ip)** On Redis, over the current and the previous hour:
  - one client with 700 of 1,000 requests: a warning with the share and the client's hash, which points to the trusted proxies;
  - 400 of 1,000: ok; 700 of 900: ok, too few to tell;
  - a client's requests in the two hours add up.
  - The cache driver: skipped.
- **AC-18 (ignored)** A rule ignored 30 times in the last 60 complete minutes: a warning that names the rule, the count and `matching.max_rules_per_request`. None: ok.
- **AC-19 (skipped)** The checks of what the rules saw are skipped, saying why:
  - with telemetry off, or a sample rate of 0;
  - `guests` and `ignored` without a manifest, whose rules they need;
  - when the store cannot be read.
- **AC-20 (flush)** The flush moves only hits, blocks and would-blocks, so the hourly rows stay as SPEC-015 makes them. A rule that was only skipped or ignored in an hour gets no row.

**Request path**
- **AC-21 (rules)** The arch tests hold:
  - the health checks and the command never compile, rebuild or change anything, never use the flush, and never touch the database;
  - the middleware never uses the health checks;
  - `src/Engine` never reads the clock, and every key comes from `KeyBuilder`.
- **AC-22 (cost)** `MiddlewareBench` measures the response path, which builds a few more records, within 10% of SPEC-015's at p50. `TelemetryBench` measures the write with the hour's traffic (SPEC-015 decision 12).

## Interfaces and files
- `src/Console/HealthCommand.php` (new): `dynamic-throttle:health {--json} {--strict}`.
- `src/Support/Health/` (new, internal):
  - `HealthChecks` runs the checks in order and returns a `HealthReport`;
  - `HealthReport` holds the results and the overall status, and serializes to the JSON above;
  - `CheckResult` holds one check's id, status and message;
  - `CheckStatus` is the enum `ok`, `warning`, `error`, `skipped`;
  - one class per check, behind a `Check` interface;
  - `HealthContext` reads once what several checks share: the manifest, the routes, the last hour's numbers.
- `src/Support/RouteMiddleware.php` (new, internal): a route's middleware in running order, where `dynamic.throttle` runs among them, and whether authentication runs before it. `Explainer` and the route checks share it (decision 10).
- `src/Support/ConfigValues.php` (new, internal): the value the package uses for each config key it falls back on. The service provider and the `config` check share it, so they never disagree (decision 14).
- `src/Console/Printable.php`: explain's ASCII-only JSON, shared with the health command.
- `src/Engine/Contracts/TelemetryBuffer.php`:
  - `record(int $minute, int $weight, list<TelemetryRecord> $records, ?string $client = null)`;
  - `topClients(int $hour, int $limit = 1): ?array<string, int>`;
  - `requests(int $hour): ?int`.
- `src/Engine/Telemetry/`:
  - `MinuteCounts` gains `guests`, `skipped` and `ignored`, 0 by default;
  - `TelemetryOutcome` gains `Skipped` and `Ignored`;
  - `TelemetryRecord` gains `guest`;
  - `TelemetryMetric` gains the three metrics;
  - both buffers write and read them, and the Redis buffer also the hour's traffic.
- `src/Engine/KeyBuilder.php`: `traffic(int $hour)` and `requests(int $hour)`, under `t:`.
- `src/Engine/Identity/ScopeIdentity.php`: the `ip` scope's identity for a grouped IP, which the traffic's client shares.
- `src/Http/RequestIdentityResolver.php`: whether the user scope found no user.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: records skipped and ignored rules and guests in `handle()`, and the client's identity in `terminate()`.
- `src/Support/MetricsFlusher.php`: skips a rule's minute without hits.
- `src/DynamicThrottleServiceProvider.php`: registers the command, and reads its config values through `ConfigValues`.

## Out of scope
- **The JSON API's health endpoint** (SPEC-017) and the dashboard's Overview (SPEC-021), which show this report.
- **Alerts:** a health check runs when someone asks. Events and the app's own monitoring cover alerting (ADR-0013).
- **Comparing the manifest with the database** (decision 11). `dynamic-throttle:status` compares the settings, and `dynamic-throttle:compile` rewrites the manifest either way.
- **The emergency settings,** bypass and scale, which `dynamic-throttle:status` shows with their end times.
- **The warning on header scopes,** which ADR-0009 puts in the dashboard, where an admin picks a rule's scope (SPEC-022). SPEC-008 listed it with these checks.
- **Other packages' middleware** beyond Laravel's `throttle`, and the paths other packages set in code, such as Livewire's and broadcasting's.
- **Checks an app adds of its own:** they can be added later without breaking anything.
- **`docs/runbook.md`,** which will point to these checks.

## Edge cases and failure modes
- **The counter store is down:**
  - `manifest` and `metrics` err;
  - the checks of what the rules saw are skipped, saying the store cannot be read;
  - the other checks still run.
- **The database is down:** no check reads it. The checks of what the rules saw take the rules from the manifest.
- **Too little traffic:** the guests and dominant-IP checks say ok below their minimums, 100 and 1,000 requests, since a few requests tell nothing.
- **Sampling:** the numbers are weighed estimates. The thresholds are shares, so sampling does not bias them. A sample rate of 0 records nothing, so the checks of what the rules saw are skipped.
- **A heavy legitimate client,** such as a partner's server, can dominate the traffic. The warning names the share and the likely cause, and says to ignore it when the client is known.
- **A public route** that a user rule matches counts guests there, and the guests check warns about it. Its message says to scope the rule to authenticated routes, or to use an IP rule for public ones.
- **A request rejected by an earlier rule** still records the later rules it skipped or ignored, which planning decided before the store answered (ADR-0026).
- **Global middleware** runs before every route's middleware, so authentication on any route runs after it.
- **Many routes:** each route check names at most ten routes, then how many more.
- **Reading the routes builds each route's controller** to read its middleware, as `php artisan route:list` does. A controller that cannot be built makes the route checks err, with its message.
- **The manifest's version is its compile time** in milliseconds, unless a server's clock ran ahead when it compiled. The version then stays ahead until the clocks catch up, and its age reads as 0, so a stopped scheduler goes unseen until then.
- **A store that expires keys when it reads them,** such as the database store, deletes its own expired telemetry keys while the checks read. That is the store's housekeeping, not a change.
- **Redis Cluster:** the two hour keys add two calls per recorded request, after the response, since each key is one call there (SPEC-015 AC-3, decision 16).
- **A client IP that cannot be read,** such as from conflicting forwarded headers, leaves the request out of the hour's traffic. Its numbers per rule are still recorded.

## End-to-end verification
- `composer test`, `composer test:drivers` for the Redis parts, and `composer analyse`, `format:check` and `refactor:check`
- `composer test:mutate`, since `src/Engine` changes
- `composer bench`: `MiddlewareBench` and `TelemetryBench`, since `handle()` builds more records and `terminate()` writes more
- the review subagent: the request path, the engine, and the public API: the command, its JSON and the check ids

It ships in five stacked PRs (ADR-0027), each green on its own:
1. **The telemetry the checks read:** guests, skipped and ignored per rule and minute, the hour's traffic on Redis, the resolver's guest, the client's identity in `terminate()`, and the flush that leaves them out, with the benchmark. AC-14, AC-15, AC-20 and AC-22.
2. **The command, with the `store` and `app-key` checks:** `dynamic-throttle:health`, its report, its JSON and its exit codes. AC-5 and AC-7, AC-1 to AC-3 for those checks, and AC-21's rules for the command and the checks.
3. **The `config` and `cache-clear` checks:** with `ConfigValues`, which the service provider now reads its config through, and `RouteMiddleware`, which `Explainer` now uses too. AC-4 and AC-6, and AC-21's rule for `RouteMiddleware`.
4. **The route checks:** `attached`, `except`, `two-limiters` and `middleware-order`. AC-8 to AC-11.
5. **The state and what the rules saw:** the `manifest`, `metrics`, `guests`, `dominant-ip` and `ignored` checks, then the docs and the spec's close. AC-12, AC-13, AC-16 to AC-19, and AC-1 to AC-3 for all thirteen checks.

## Open questions
None. The maintainer decided the first two on 2026-10-05. The others are recorded as recommended (ADR-0027), for the maintainer to veto in the PR.

## Decisions
1. **The checks of what the rules saw read telemetry, extended** (decided by the maintainer).
   - The guests, skipped and ignored numbers join SPEC-015's per rule and minute.
   - On Redis, the hour's clients and requests join them too.
   - All of it goes in the same call after the response, so the response path pays only for building a few more records.
   - **Rejected:**
     - static checks only, which leaves three ADRs' promises open;
     - per-process counters in APCu, which see one server, and nothing on PHP-FPM without APCu.
2. **A new `dynamic-throttle:health` command** (decided by the maintainer).
   - Each check is `ok`, `warning`, `error` or `skipped`, with a message.
   - It exits 1 on an error, and with `--strict` on a warning too, so a deploy script can choose.
   - `--json` gives SPEC-017's endpoint the same report.
   - The check ids, the statuses and the JSON keys are public once released (ADR-0017). The messages are not.
   - **Rejected:** folding it into `dynamic-throttle:status`, whose output and exit code are about what servers apply.
3. **Errors are what break limiting outright; the rest are warnings.**
   - Errors: a config the driver cannot be built from, the `null` store, no manifest, a store that cannot be read.
   - Warnings: what limits less than the admin thinks.
4. **The checks of what the rules saw read the counter store's last hour, not the hourly table.**
   - The store holds the last 2 hours, which covers the hour a health check cares about, and the table needs no new columns.
   - `guests` and `ignored` read the last 60 complete minutes. `dominant-ip` reads the current and the previous hour's keys.
   - **Rejected:** new columns in `dynamic_throttle_metrics_hourly`, a schema change for numbers that nothing shows yet.
5. **The thresholds are fixed, not config:** a check is advice, and its message gives the numbers.
   - `guests`: half or more of at least 100 matched requests.
   - `dominant-ip`: half or more of at least 1,000 requests.
   - `ignored`: any.
   - `manifest`: 10 minutes without a compile, two scheduled compiles missed; 500 rules, ADR-0007's number; 1,000 exemptions, where a load of the manifest took about 2.5 ms in Phase 2 (`benchmarks/results/phase-2-exemptions.md`), which every request pays on PHP-FPM without APCu. SPEC-009 left that number to the Phase 2 exit, which did not set one.
   - `metrics`: 10 minutes, ADR-0013's number.
6. **The traffic's client is the `ip` scope's identity:** the client IP that the trusted proxies give, IPv6 grouped by `ipv6_prefix`, hashed with the app's key (ADR-0024).
   - So the warning's client is the same hash that an IP rule's offenders show, and `explain` shows for an IP, and no raw IP reaches the store.
   - It is worked out in `terminate()`, after the response.
7. **A request is a guest when the user scope finds no user,** with the same guards as user rules (`scopes.user.guards`). A user scope that fails is not counted as a guest.
8. **`except` covers a package's whole path:** both `<path>` and every `<path>/…` must fall under a pattern. The packages are the ones whose defaults are in `except`, read from their own config keys. This is the only place the package reads those keys (ADR-0020's rejected alternative).
9. **`cache-clear` warns only for the Redis driver.** The cache driver keeps its counters in a cache store by design, as Laravel's `throttle` does, so `cache:clear` resetting them is expected there. ADR-0020 point 2 states the problem for the Redis driver, which shares the `cache` connection's database by default.
10. **The route checks read routes as explain does,** through one shared class, so the two never disagree.
11. **A manifest is stale when the scheduled compile has not rewritten it,** which every run does, with a new version.
    - **Rejected:** comparing it with the database, which reads every table and builds every rule, to report what `dynamic-throttle:compile` fixes either way. `dynamic-throttle:status` already compares the settings.
12. **`skipped` counts every rule that found no identity,** user rules and the others. The `guests` check reads only user rules', and the dashboard can show the others' later.
13. **`attached` is a check of its own,** though no ADR asks for it: nothing is attached by default (ADR-0020 point 11), so an install that forgets to attach the middleware limits nothing, silently.
14. **A config value the package replaces with its default is a warning,** since the app's intention is silently ignored, such as a sample rate written as a string. The service provider and the check take the values from one class.
15. **The store check follows ADR-0005 point 4 and SPEC-002's measurements.** The `octane` store counts per server, as APCu does. A store of another driver is ok with a note: a warning for every custom store would make `--strict` unusable, and ADR-0005's fallback marks a store "not supported" only once it misbehaves.
16. **On Redis, the hour's traffic is two keys:** a sorted set of the clients, and a hash whose one field counts the requests, which the record script already writes as it writes the minute's hash.
    - **Rejected:** the count in the sorted set, under a member that is never trimmed. It saves a key, and a call per request on Redis Cluster, at the cost of a member that every reader must skip.
