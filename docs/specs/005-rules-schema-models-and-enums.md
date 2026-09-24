# SPEC-005: Rules schema, models, backed enums and config merging

- Status: In progress
- ADRs: ADR-0007, ADR-0008, ADR-0009, ADR-0010, ADR-0012, ADR-0015, ADR-0017, ADR-0020, ADR-0022
- Roadmap phase: 2

## Problem
Rules are data (ADR-0001), stored in the database (ADR-0007). Before anything can compile, match or enforce them, they need a table, a model and typed values. This spec also adds the package's first nested config key, `database.connection`. ADR-0020 point 10 requires the config merge to ship first, so that a config the app published earlier still gets new nested defaults.

## Behavior
- **Config merge (ADR-0020 point 10).** The service provider merges the app's config over the package defaults with its own helper, not Laravel's:
  - Maps are merged key by key, at every depth, so new defaults appear after an upgrade.
  - Lists are replaced as a whole, so entries a user removed stay removed.
  - The app's values win, including `null`.
  - An empty array where the default is a map keeps the defaults.
  - Keys the package does not know are kept.
  - When the config is cached, nothing is merged, as with Laravel's own helpers.
- **Database connection.** Config `database.connection`, from `DYNAMIC_THROTTLE_DB_CONNECTION`, default `null`: the app's default connection. The migration and the model both use it (ADR-0020 point 7).
- **One table in this spec: `dynamic_throttle_rules`.** Each other table ships with the spec that first uses it: exemptions with SPEC-009, settings with SPEC-013, the audit log with SPEC-019, metrics with SPEC-015.
- **Published migrations.** The migration is published with `publishesMigrations()` under the tag `dynamic-throttle-migrations`. That gives it a fresh timestamp when the app's `database.migrations.update_date_on_publish` is on, which Laravel's skeleton sets. It is never loaded automatically: the app owns its schema (ADR-0020 point 7).
- **Backed enums**, one per string column with a fixed set of values (ADR-0007: string columns validated by enums, never database `enum` columns). They live in `Afzal\DynamicThrottle\Enums`, outside `Models`, because the request path will use them and must never touch the database layer (ADR-0007, arch tests).
- **The `Rule` model** casts every column to its PHP type, and every enum column to its enum.

## Acceptance criteria

### Config merge (`ConfigMerger::merge($defaults, $app)`)
- **AC-1** Maps merge key by key: defaults `['a' => 1, 'm' => ['x' => 1, 'y' => 2]]` with app `['m' => ['x' => 9]]` give `['a' => 1, 'm' => ['x' => 9, 'y' => 2]]`.
- **AC-2** Lists are replaced as a whole:
  - Defaults `['l' => ['p', 'q', 'r']]` with app `['l' => ['p']]` give `['l' => ['p']]`, not `['p', 'q', 'r']`.
  - With app `['l' => []]`, the list is empty.
- **AC-3** An empty array where the default is a map keeps the defaults: defaults `['m' => ['x' => 1]]` with app `['m' => []]` give `['m' => ['x' => 1]]`.
- **AC-4** The app's values win, and unknown keys are kept:
  - A scalar replaces the default.
  - `null` replaces the default.
  - A non-empty list where the default is a map replaces it, so validation can report it later.
  - A key missing from the defaults is kept.
- **AC-5** Merging works at any depth: with defaults `['a' => ['b' => ['c' => 1, 'd' => 2]]]`, app `['a' => ['b' => ['c' => 3]]]` gives `['a' => ['b' => ['c' => 3, 'd' => 2]]]`.
- **AC-6** The provider uses the helper:
  - Say an app published its config before `database` existed: `enabled`, `driver`, `store`, `redis_connection`, and `prefix` set to `app:`. After the provider registers, `prefix` is still `app:`, and `database.connection` exists, set to its default `null`.
  - An app config with `'database' => []` still gets `database.connection`. Laravel's `mergeConfigFrom` would leave the empty array, so only the helper passes this case.

### Config key
- **AC-7** `DYNAMIC_THROTTLE_DB_CONNECTION=secondary` sets `database.connection` to `secondary`. Without it, the value is `null`.

### Migration
- **AC-8** The migration is published, never loaded:
  - `ServiceProvider::pathsToPublish()` for the tag `dynamic-throttle-migrations` maps the package's `database/migrations` folder to the app's.
  - The migrator's paths do not include the package's folder.
- **AC-9** The migration creates `dynamic_throttle_rules` with these columns, and rolling back drops the table:

  | Column | Type | Default | Notes |
  |---|---|---|---|
  | `id` | unsigned big integer, auto-increment | | Counters use it as the rule id (ADR-0006). Ids are never reused. |
  | `name` | string(100) | | Shown in the dashboard, CLI and logs. |
  | `description` | text, nullable | `null` | Why the rule exists. |
  | `method` | string(7) | `*` | `RequestMethod` |
  | `pattern` | string(255) | | `Str::is` pattern, without the leading slash (ADR-0008, ADR-0015) |
  | `scope` | string(32) | | `Scope` |
  | `scope_parameter` | string(64), nullable | `null` | The header name for `header`, the resolver name for `custom` (ADR-0009) |
  | `max_attempts` | unsigned integer | | ≥ 1 (validated in SPEC-018) |
  | `window_seconds` | unsigned integer | | 1 to 86,400 (validated in SPEC-018) |
  | `priority` | unsigned integer | 100 | Lower runs first. Equal priorities run in id order (ADR-0020 point 6). |
  | `mode` | string(16) | `enforce` | `RuleMode` (ADR-0012) |
  | `on_failure` | string(16) | `open` | `FailurePolicy` (ADR-0010) |
  | `on_missing_identity` | string(16) | `ip` | `MissingIdentity` (ADR-0009, ADR-0022) |
  | `response_override` | text, nullable | `null` | A JSON body, ≤ 4 KB (validated in SPEC-018). Text, not `json`: MySQL's JSON type reorders object keys, so the body would not come back as written (AC-14). |
  | `enabled` | boolean | `true` | Disabled rules are not compiled. |
  | `created_at`, `updated_at` | timestamps | | |
- **AC-10** The migration uses `database.connection`. With `secondary` configured (a second SQLite connection), the table exists on `secondary` and not on the default connection.
- **AC-11 (drivers)** The migration runs up and down on MySQL 8.4 and PostgreSQL 16 (the docker compose services), and on SQLite in the regular suite (ADR-0002). Each drivers test uses its own random table prefix, so parallel runs never share a table.

### Enums
- **AC-12** The enums have exactly these cases and values:
  - `RequestMethod`: `Any` = `*`, `Get` = `GET`, `Head` = `HEAD`, `Post` = `POST`, `Put` = `PUT`, `Patch` = `PATCH`, `Delete` = `DELETE`, `Options` = `OPTIONS`
  - `Scope`: `Ip` = `ip`, `User` = `user`, `ApiToken` = `api_token`, `Header` = `header`, `Global` = `global`, `Custom` = `custom`
  - `RuleMode`: `Enforce` = `enforce`, `DryRun` = `dry_run`
  - `FailurePolicy`: `Open` = `open`, `Closed` = `closed`
  - `MissingIdentity`: `FallBackToIp` = `ip`, `Skip` = `skip`

### Model
- **AC-13** A rule created with only `name`, `pattern`, `scope`, `max_attempts` and `window_seconds` has every default of AC-9, both on the returned model and when read back: `RequestMethod::Any`, priority 100, `RuleMode::Enforce`, `FailurePolicy::Open`, `MissingIdentity::FallBackToIp`, enabled, no scope parameter, no response override.
- **AC-14** Types survive a round trip on SQLite, MySQL and PostgreSQL (the MySQL and PostgreSQL runs are drivers tests):
  - enum columns return enum instances
  - `max_attempts`, `window_seconds` and `priority` return integers
  - `enabled` returns a boolean
  - `response_override` returns the same array that was saved
- **AC-15** The model uses `database.connection`: with `secondary` configured, a created rule lands on `secondary`.
- **AC-16 (arch)** `Afzal\DynamicThrottle\Enums` depends on nothing from `Illuminate\Database`. The existing rules keep `Models` away from `Engine` and `Http\Middleware`.

## Interfaces and files
- `src/Support/ConfigMerger.php`: `ConfigMerger::merge(array $defaults, array $app): array`, `@internal`.
- `src/DynamicThrottleServiceProvider.php`:
  - merges config with `ConfigMerger` instead of `mergeConfigFrom`
  - publishes the migration with `publishesMigrations()`, tag `dynamic-throttle-migrations`
- `config/dynamic-throttle.php`: `database.connection`, with the env var `DYNAMIC_THROTTLE_DB_CONNECTION`.
- `database/migrations/2026_09_24_000001_create_dynamic_throttle_rules_table.php`, using the configured connection.
- `src/Models/Rule.php`: table `dynamic_throttle_rules`, with the configured connection and casts.
- `src/Enums/RequestMethod.php`, `Scope.php`, `RuleMode.php`, `FailurePolicy.php`, `MissingIdentity.php`.
- `.gitattributes` and the CI allowlist ship `database/` to users (the roadmap's "What is public").
- Tests:
  - `tests/Unit/Support/ConfigMergerTest.php`: AC-1 to AC-5
  - `tests/Feature/ServiceProviderTest.php`: AC-6, AC-8
  - `tests/Unit/ConfigTest.php`: AC-7
  - `tests/Feature/Models/RuleTest.php`: AC-9, AC-10, AC-13, AC-15, and AC-14 on SQLite
  - `tests/Unit/Enums/EnumsTest.php`: AC-12
  - `tests/Drivers/RuleSchemaTest.php`: AC-11, and AC-14 on MySQL and PostgreSQL
  - `tests/Arch/ArchTest.php`: AC-16
- `CHANGELOG.md`: the `database.connection` key, the migration and the model.

## Out of scope
- Validating rule values: max ≥ 1, window 1 to 86,400, pattern length, the 500-rule cap, `response_override` size. That is SPEC-018. The compiler (SPEC-006) skips and reports invalid rows.
- Compiling rules into the manifest (SPEC-006), matching (SPEC-007) and resolving scopes (SPEC-008).
- The exemptions, settings, audit and metrics tables (open question 1).
- A model factory for host apps. `DynamicThrottle::fake()` (SPEC-010) covers testing in host apps.

## Edge cases and failure modes
- **An unknown enum value in a row**, written by hand or by a later version, makes reading that attribute throw `ValueError`. The model never guesses. SPEC-006's compiler must skip such rows and report them, so one bad row cannot stop every rule.
- **Id reuse.** Counters are keyed by rule id (ADR-0006), so a new rule that reused a deleted rule's id would inherit its counters for up to two windows. Auto-increment ids are not reused: SQLite with `AUTOINCREMENT` (Laravel's default), PostgreSQL sequences, and MySQL 8 and MariaDB 10.2.4+, which persist the counter.
- **Changing migrations before 0.1.0.** No version has been released, so this migration can still change until 0.1.0 is tagged. After that, only new migrations (ADR-0017).
- **The configured connection does not exist.** The migration and the model fail with Laravel's usual "Database connection [x] not configured". That only happens in admin actions and the compiler, never on the request path (ADR-0007).

## End-to-end verification
- `composer test`, `composer analyse`
- `docker compose up -d && composer test:drivers` (AC-11, AC-14)
- `scripts/verify-install.sh 12` and `13`: `vendor:publish --tag=dynamic-throttle-migrations` and `migrate` work in a fresh app.

## Open questions
None. The maintainer answered all four on 2026-09-24, accepting each recommendation:
1. One table per spec. This spec creates only the rules table.
2. Auto-increment rule ids.
3. `on_missing_identity: skip` works on every scope that can miss an identity (ADR-0022, which amends ADR-0009).
4. Priority defaults to 100, unsigned, and lower runs first.
