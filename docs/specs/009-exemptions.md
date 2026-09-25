# SPEC-009: Exemptions

- Status: In progress
- ADRs: ADR-0007, ADR-0008, ADR-0009, ADR-0010, ADR-0012, ADR-0017, ADR-0020, ADR-0023, ADR-0024, ADR-0025
- Roadmap phase: 2

## Problem
Some clients must not be limited at all, or not by one rule: an uptime monitor, the office network, a partner's servers, a user running a support import, a tenant on a special contract. Today the only way is to rewrite the rules. ADR-0007 and ADR-0008 already decided the outline:
- exemptions are rows in their own table, compiled into the manifest;
- they are checked per rule before counting;
- an exemption without a rule covers every rule;
- an expired exemption is ignored.

Nothing implements them yet.

## Behavior
- **An exemption** says that a client is not limited by one rule, or by any rule, until it expires. It has:
  - **a subject**, the client it exempts (decision 1):
    - `ip`: an address or a CIDR range, IPv4 or IPv6, such as `203.0.113.7`, `10.0.0.0/8` or `2001:db8::/32`.
      - It matches the client IP as `$request->ip()` returns it, so the app's trusted proxies decide it (ADR-0009).
      - The address is taken whole, before IPv6 grouping.
      - IPv4-mapped and NAT64 addresses count as their IPv4 address, as in the `ip` scope (SPEC-008). So does an exemption written that way, such as an address copied from a log: `::ffff:203.0.113.7` exempts `203.0.113.7`.
    - `user`: a user, written as its class and id, `App\Models\User:42`. It matches the user the `user` scope finds (SPEC-008): the same guards, and no lookup of its own.
    - `custom`: a value of a registered custom scope, with the scope's name, such as `tenant` = `acme`.
      - It matches exactly, as the scope's counters do: `acme` and `ACME` differ.
      - The resolver runs at most once per request, for exemptions and rules together.
  - **a rule**: one rule's id, or none for every rule (ADR-0008).
  - **an expiry**: optional. From that second on, the exemption is ignored, even before the next compile. An expiry in another time zone than the app's keeps its instant.
  - **a reason**: optional text for admins.
- **In the evaluator** (SPEC-007), for each matched rule:
  - The order is: the cap (ADR-0025), then exemptions, then the rule's identity (SPEC-008).
  - An exempt rule makes no attempt and takes no slot (ADR-0025). Its identity is never resolved, so a scope that fails cannot fail an exempt client.
  - The evaluation lists each exempt rule, with the id of the exemption that matched, in a list of its own: not with the rules skipped for a missing identity.
  - Dry-run rules are exempted like enforcing ones (ADR-0012).
  - An exemption only removes rules from this request. It never rejects, releases or changes a count.
  - SPEC-007 sketched an exemption as `resolve()` returning `null`. A check of its own lets the evaluation name the exemption, and keeps the rule's identity unresolved.
- **The checks run in a fixed order**: IP, then user, then custom scopes by name. They stop at the first match, so a custom resolver runs only when no IP or user exemption of the rule matched.
  - Within a subject, the matching exemption with the lowest id is the one named, whether it is the rule's or a global one.
- **When an exemption check fails**, for example because the client IP cannot be read or a custom resolver throws (decision 2):
  - The failed check counts as no match. If a later check matches, the rule is exempt.
  - Otherwise the rule counts the request as usual, and the first failed check's error is listed in the evaluation. SPEC-012 reports it.
  - A failing check can therefore never switch limits off.
- **Storage.** A table `dynamic_throttle_exemptions` (ADR-0020 point 7):
  - Its migration is published under the tag `dynamic-throttle-migrations`, and never loaded (SPEC-005).
  - A model `Exemption` uses `database.connection`.
  - Deleting a rule deletes its exemptions.
- **The manifest** (SPEC-006):
  - The format becomes 2, with an `exemptions` list next to the rules.
  - Saving or deleting an exemption recompiles after the transaction commits, as for rules (ADR-0007).
  - The compiler leaves out:
    - expired exemptions;
    - exemptions of rules that are not compiled, because they are disabled or invalid;
    - invalid rows, which it skips and reports, as it does for rules.
  - Without the exemptions table, as after an upgrade whose migration has not run yet, it compiles the rules alone and reports the missing table. Limits keep working, and no client is exempt until the migration runs.
  - The manifest never holds a raw user id or custom value: they are hashed with the identity key (ADR-0024, decision 3). IP addresses and ranges are kept, normalized, because matching needs them. A single address is a range of one: `/32` or `/128`.
  - Exemptions are grouped by rule and subject when the manifest loads, like the index (ADR-0023). A request then checks only the exemptions of its matched rules, and the global ones (decision 4):
    - one set lookup for users, and one per custom scope;
    - for IP exemptions, one set lookup per prefix length in use, such as `/32`, `/24` and `/8`: the client address is cut to that length and looked up. A thousand ranges cost no more than ten of the same lengths.
- **Helpers** for writing exemptions before the dashboard and API exist:
  - `Exemption::forIp('10.0.0.0/8')`
  - `Exemption::forUser($user)`
  - `Exemption::forCustom('tenant', 'acme')`

  Each returns an unsaved model. `->forRule($rule)`, `->until($time)` and `->because('reason')` set the other fields.
- **Location:**
  - The pure parts live in `src/Engine/Exemptions`, so the mutation gate covers them: matching addresses and ranges, and the lookup per rule.
  - Reading the request stays in `RequestIdentityResolver`, with its per-request memo (SPEC-008).

## Acceptance criteria
Unless stated otherwise, matching criteria use `RequestIdentityResolver` on a `Request::create()` request, with the manifest's exemptions and the fake clock.

### Storage and model
- **AC-1** The migration creates `dynamic_throttle_exemptions`. Rolling back drops it.

  | Column | Type | Notes |
  |---|---|---|
  | `id` | unsigned big integer, auto-increment | |
  | `rule_id` | unsigned big integer, nullable | Foreign key to `dynamic_throttle_rules.id`, deleted with the rule. `null` is every rule. The name is decision 5. |
  | `subject` | string(16) | `ExemptionSubject` |
  | `subject_parameter` | string(64), nullable | The custom scope's name |
  | `value` | string(255) | An address or range, `Class:id`, or a custom value |
  | `reason` | string(255), nullable | |
  | `expires_at` | datetime, nullable | `null` never expires. A datetime, not a timestamp: MySQL's TIMESTAMP ends in January 2038. |
  | `created_at`, `updated_at` | timestamps | |
- **AC-2** The migration uses `database.connection`, is published with the rules migration, and is never loaded (SPEC-005 AC-8, AC-10).
- **AC-3 (drivers)** On SQLite, MySQL and PostgreSQL:
  - The migration runs up and down.
  - Deleting a rule deletes its exemptions, and not other rules' or global ones.
- **AC-4** `ExemptionSubject` has exactly `Ip` = `ip`, `User` = `user` and `Custom` = `custom`.
- **AC-5** The model casts `subject` to `ExemptionSubject` and `expires_at` to a date. `Exemption::rule()` and `Rule::exemptions()` relate them.
- **AC-6** The helpers build the rows:
  - `forIp('10.0.0.0/8')` gives subject `ip` and value `10.0.0.0/8`.
  - `forUser` of user 42 of `App\Models\User` gives value `App\Models\User:42`.
  - `forCustom('tenant', 'acme')` gives parameter `tenant` and value `acme`.
  - `forRule`, `until` and `because` set `rule_id`, `expires_at` and `reason`.
  - `forRule` refuses a rule that is not saved: its missing id would exempt the client from every rule.

### Compiling
- **AC-7** Saving or deleting an exemption bumps the manifest's version after the transaction commits. A save inside a transaction that rolls back leaves the manifest unchanged.
- **AC-8** The manifest has `format` 2 and an `exemptions` list, ordered by id. Each entry has exactly `id`, `rule` (a rule id or `null`), `subject`, `parameter`, `match` and `expires_at` (Unix seconds or `null`).
- **AC-9** These rows are left out:
  - an exemption that has expired;
  - an exemption of a disabled rule;
  - an exemption of a rule the compiler skipped as invalid.
- **AC-10** These rows are skipped and reported, and the rest compile:
  - an address or range that is not one (`10.0.0.0/33`, `2001:db8::/129`, `not-an-ip`, `10.0.0.1/-1`, or one with a NUL byte);
  - an empty value;
  - a user value without a class and an id;
  - a custom exemption without a scope name, or with a name that is not a scope name;
  - an unknown subject.

  `dynamic-throttle:compile` prints the exemptions after the rules: `Compiled manifest version V: 1 rule, 0 skipped; 1 exemption, 1 skipped.`

  Without the exemptions table, the rules compile with no exemptions, and the missing table is reported.
- **AC-11** No raw identifier reaches the manifest:
  - `App\Models\User:42` and the custom value `acme` appear nowhere in its JSON.
  - Their `match` is 64 lowercase hex characters, and differs between two app keys.
  - Ranges are normalized: `10.0.0.1/8` becomes `10.0.0.0/8`, `2001:DB8::/32` becomes `2001:db8::/32`, `::ffff:203.0.113.7` becomes `203.0.113.7/32`, and a single address gets `/32` or `/128`.
- **AC-12** A manifest with an unknown field in an exemption, a wrong type, or format 1 is corrupt (SPEC-006 AC-16).

### Matching
- **AC-13 `ip`:**
  - `203.0.113.7` exempts that address, and not `203.0.113.8`.
  - `203.0.113.0/24` exempts `203.0.113.200`, and not `203.0.114.1`.
  - `2001:db8::/32` exempts `2001:db8:ffff::1`, and not `2001:db9::1`.
  - `203.0.113.0/24` exempts the client `::ffff:203.0.113.9` and the client `64:ff9b::203.0.113.9`.
  - A request without an IP matches no `ip` exemption.
- **AC-14 `user`:**
  - `App\Models\User:42` exempts user 42, and not user 43, a guest, or an `Admin` with id 42.
  - With the default guards, a user that auth has not loaded is not exempt, and no query runs (SPEC-008 AC-5).
- **AC-15 `custom`:**
  - `tenant` = `acme` exempts a request whose resolver returns `acme`, and not `globex` or `null`.
  - Three rules and two `tenant` exemptions call the resolver once.
  - An exemption for `plan` = `acme` does not match the `tenant` value `acme`.
- **AC-16 Scope of an exemption, and which one is named:**
  - A global exemption exempts every matched rule.
  - An exemption for rule `a` exempts `a` only.
  - A client in a global range (id 3) and a range of rule `a` (id 7) is exempt from `a` by 3.
  - A matching `ip` exemption stops the checks: the resolver of a `custom` exemption on the same rule is never called.
  - With `custom` exemptions for `plan` (id 4) and `tenant` (id 2) that both match, the rule is exempt by 4, because `plan` comes first by name.
- **AC-17 Expiry.** An exemption that expires at T0+60 exempts at T0+59, and not at T0+60, without a new compile. An expiry written through the model in another time zone than the app's, as a date or as text with an offset, keeps its instant.

### Evaluation
- **AC-18** An exempt rule:
  - makes no attempt, and its counter stays 0;
  - is listed as exempt, with the exemption's id;
  - never has its identity resolved;
  - takes no slot: with a cap of 1, an exempt rule followed by a counting rule leaves the second one counting (ADR-0025).
- **AC-19** Exemptions stack with the other rules:
  - A client exempt from a strict rule is still counted, and rejected, by a lenient rule on the same path.
  - A dry-run rule is exempted like an enforcing one.
- **AC-20** A failed exemption check, on a `global` rule:
  - With an `ip` exemption and a client IP that cannot be read (conflicting forwarded headers), the rule is not exempt and counts the request. The error is listed in the evaluation.
  - The same holds for a `custom:plan` exemption whose resolver throws.
  - With that failing `custom:plan` exemption and a matching `ip` exemption, the rule is exempt.
  - `evaluate()` does not throw.

### Arch and cost
- **AC-21 (arch)** `Engine\Exemptions` follows the engine's rules: no database and no wall clock. The expiry check reads the `Clock`.
- **AC-22** A request with 100 exemptions and a warm manifest runs no database query. The resolver reads the clock once per request, and not at all when the manifest has no exemptions.
- **AC-23 (benchmark)** `ExemptionSetBench` loads manifests of 100 rules with none, 100 and 1,000 exemptions, each half ranges of four prefix lengths, a third users and the rest custom values. The results record, with the hardware:
  - what they add to decoding;
  - what checking a request against them costs, which should not grow with their number.

## Interfaces and files
- `src/Enums/ExemptionSubject.php`
- `database/migrations/2026_09_25_000001_create_dynamic_throttle_exemptions_table.php`
- `src/Models/Exemption.php`, with the helpers, and `Rule::exemptions()`
- `src/Compiler/ManifestObserver.php`: `RuleObserver`, renamed and registered on both models.
- `src/Compiler/ManifestCompiler.php`: compiles the exemptions, and hashes their values with `IdentityHasher`.
- `src/Compiler/CompileResult.php`: gains `exemptions` and `skippedExemptions`, which `dynamic-throttle:compile` prints.
- `src/Compiler/InvalidExemptionSkipped.php`: what the compiler reports for an invalid row.
- `src/Compiler/ExemptionsTableMissing.php`: what it reports when the table does not exist.
- `src/Manifest/CompiledExemption.php`: an exemption as the manifest stores it, as strict as `CompiledRule`. Its fields are `id`, `rule`, `subject`, `parameter`, `match` and `expiresAt`.
- `src/Manifest/ManifestCodec.php`: format 2. `encode()` takes the exemptions after the rules.
- `src/Manifest/Manifest.php`: `new Manifest($version, $rules, $exemptions)`. `$manifest->exemptions` is their `ExemptionSet`, built when the manifest loads.
- `src/Engine/Exemptions/`:
  - `IpRange` parses and normalizes addresses and ranges, reads a client address (unwrapping IPv4-mapped and NAT64 addresses), and cuts an address to a prefix length.
  - `ExemptionSet` groups exemptions by rule and subject. `match(string $ruleId, int $nowMicroseconds, ExemptionSubjects $subjects): ?string` runs the checks in order, and gives the id of the exemption that applies. `all()` lists the exemptions.
- `src/Engine/Contracts/ExemptionSubjects.php`: what the request is, for each subject: the client IP, and the hashes of the user and of a custom scope's value. Each may throw `IdentityUnavailableException`.
- `src/Engine/Contracts/IdentityResolver.php`: gains `exemption(CompiledRule): ?string`, the id of the exemption that applies, or `null`. When no check matched and one failed, it throws that check's `IdentityUnavailableException`.
- `src/Engine/Stacking/RuleEvaluator.php` and `Evaluation.php`: `exempted`, a list of `RuleExemption` (the rule and the exemption's id), and `exemptionFailures`, a list of `RuleFailure`.
- `src/Http/RequestIdentityResolver.php`: implements `exemption()` with its memo, the hasher and the clock. It takes the manifest's exemptions: `make(RequestIdentityResolver::class, ['request' => $request, 'exemptions' => $manifest->exemptions])`.
- Tests:
  - `tests/Feature/Models/ExemptionTest.php`: AC-1 to AC-3 on SQLite, AC-5, AC-6
  - `tests/Drivers/ExemptionSchemaTest.php`: AC-3 on MySQL and PostgreSQL
  - `tests/Unit/Enums/EnumsTest.php`: AC-4
  - `tests/Feature/Compiler/ExemptionCompileTest.php`: AC-7 to AC-11
  - `tests/Feature/Console/CompileCommandTest.php`: the output in AC-10
  - `tests/Unit/Manifest/CompiledExemptionTest.php` and `ManifestCodecTest.php`: AC-8, AC-12
  - `tests/Unit/Engine/Exemptions/IpRangeTest.php` and `ExemptionSetTest.php`: the pure parts of AC-11 and AC-13 to AC-17
  - `tests/Feature/Http/ExemptionMatchingTest.php`: AC-13 to AC-17, AC-20, AC-22
  - `tests/Unit/Engine/Stacking/RuleEvaluatorTest.php`: AC-18 to AC-20
  - `tests/Arch/ArchTest.php`: AC-21
  - `benchmarks/Engine/ExemptionSetBench.php`: AC-23, with the results in `benchmarks/results/phase-2-exemptions.md`

## Out of scope
- Managing exemptions in the dashboard (SPEC-023), the JSON API (SPEC-017) or Artisan.
- The audit log of exemption changes (SPEC-019).
- The explain command (SPEC-014) and the dashboard's explain tool (SPEC-026).
- Validating exemptions when they are saved, which is SPEC-018. Until then the compiler skips invalid rows, as it does for rules.
- The middleware that passes the manifest's exemptions to the resolver (SPEC-010).
- The emergency switch that bypasses every rule (SPEC-013).
- `api_token` and `header` subjects (decision 1).

## Edge cases and failure modes
- **Deploying the new format.**
  - A server with this version reads the old manifest as corrupt. One request rebuilds it after its response (SPEC-006).
  - During a rolling deploy, old and new servers rebuild each other's manifests, so rules fall back to what each server last read. On PHP-FPM without APCu, that means no rules at all.
  - The fix is to run `dynamic-throttle:compile` once every server runs the new version.
- **Upgrading without the migration.** The compiler finds no exemptions table, so it compiles the rules alone and reports the missing table on every compile. Limits keep working, and no client is exempt until the app publishes and runs the migration (ADR-0017).
- **Proxies.** An `ip` exemption trusts the client IP the app computes. Behind a load balancer that is not a trusted proxy, every client has the balancer's IP, so exempting that IP exempts everyone (ADR-0009). SPEC-016's health check can warn about it.
- **Rotating `APP_KEY`** changes the hashes. User and custom exemptions stop matching until the next compile: at most 5 minutes with the scheduler, or at once with `dynamic-throttle:compile`. IP exemptions are not affected.
- **An exemption of a disabled rule** stays in the table, and applies again when the rule is enabled.
- **Writes that bypass the model.** The query builder writes a date's wall-clock time in the date's own zone, so an expiry from another zone than the app's moves by their difference. It also skips the recompile (SPEC-006). Write exemptions through the model.
- **Daylight saving time.** The column holds wall-clock time, so in an app zone with daylight saving time, the hour that repeats in autumn is ambiguous: an expiry in its second pass reads back an hour early. Running the app in UTC, as Laravel's default config does, avoids it.
- **SQLite without foreign keys.** Laravel turns them on by default (`DB_FOREIGN_KEYS`). Without them, deleting a rule leaves its exemptions in the table, and the compiler leaves them out, like those of any rule it does not compile.
- **An exemption and `skip` on the same rule.** The exemption is checked first, so the rule is listed as exempt.
- **One failing scope, used by an exemption and by the rule itself.** The rule fails (SPEC-008), and its exemption check is listed as failed too, with the same cause: the same exception for a user or custom scope, and one per request for an unreadable client IP. SPEC-012 reports the cause once.
- **Headers.** An exempt rule sets no rate-limit headers, because it counted nothing. When every rule is exempt, the response has none (SPEC-010).
- **Many exemptions** cost decoding time on every manifest load on PHP-FPM without APCu. Checking a request costs one lookup per subject and prefix length, however many there are. AC-23 measures both (decision 4).

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`
- `docker compose up -d && composer test:drivers` (AC-3)
- `composer test:mutate`: `Engine\Exemptions` is in the gate
- `composer bench` (AC-23)

## Open questions
None. The maintainer answered all five on 2026-09-25, and left 3 and 4 to the best option:
1. The subjects are `ip` (addresses and ranges), `user` and `custom`.
   - Laravel's own `throttle` middleware has no exemption list: a named limiter's closure returns `Limit::none()` for the clients it exempts, in code. A `custom` scope keeps that freedom: the app's developers write the resolver, and admins exempt its values.
   - Not `api_token`: the table would have to keep a secret, or a hash an admin cannot recognize. A token's user can be exempted instead.
   - Not `header`: a client can send any header value, so a header exemption is a way around every limit (ADR-0009). An app that wants one, such as a secret header for its own services, can read it in a custom scope, deliberately.
2. A failed exemption check counts as no match, and the first failed check's error is listed for reporting. Failing the rule instead would, with the default fail-open policy, let one broken resolver switch off every rule that has an exemption.
3. User and custom values are hashed with the identity key (ADR-0024). IP addresses and ranges are kept, normalized:
   - Matching needs ranges as they are, and a single address is a range of one, so one code path serves both.
   - Exempt addresses are a few rows admins chose, not every client's IP, which is what ADR-0024 protects.
   - The cost: after `APP_KEY` rotates, user and custom exemptions match again from the next compile, at most 5 minutes later with the scheduler. Until then those clients are limited, not let through.
4. No cap. IP exemptions are grouped by prefix length, so checking a request costs the same with ten or a thousand of them. AC-23 measures 100 and 1,000 exemptions, and the Phase 2 exit benchmark decides whether SPEC-016's health check warns above some number.
5. The column is `rule_id`:
   - It is the name Eloquent infers for `belongsTo(Rule::class)` and `hasMany(Exemption::class)`, so the relations need no key names.
   - It cannot clash with other packages: a column belongs to its table, and ADR-0020 already gave the table a distinctive name.
   - ADR-0007, which wrote `throttle_rule_id` before ADR-0020 renamed the tables, lists this spec as amending it.
