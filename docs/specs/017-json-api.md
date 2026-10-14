# SPEC-017: The dashboard's JSON API

- Status: Approved
- ADRs: ADR-0011, ADR-0013, ADR-0015, ADR-0017, ADR-0018, ADR-0024
- Roadmap phase: 4

## Problem

The dashboard's pages (Phase 5) need a server to read from and to send changes to, and ADR-0018 puts every dashboard action behind a JSON API first, so the pages, the CLI and a later Filament plugin share one tested backend. SPEC-018 built the frame: the route group, the gates, the validation and the confirmations. Only `api/me` uses it so far.

## Behavior

Every endpoint lives in SPEC-018's route group, under `{path}/api` (`/dynamic-throttle/api` by default), and is named `dynamic-throttle.api.*`. The API is internal, not covered by SemVer, until it is declared stable (ADR-0018 point 1, ADR-0017).

**Reading** needs either gate (SPEC-018):

| Endpoint | Answers |
|---|---|
| `GET api/rules` | Every rule, in evaluation order (priority, then id), 100 a page |
| `GET api/rules/{id}` | One rule, with the exemptions that name it |
| `GET api/exemptions` | Every exemption, newest first, 100 a page; `?rule={id}` for one rule's |
| `GET api/settings` | Bypass and the scale factor: on or off, and until when |
| `GET api/metrics?period=hour` | Each rule's requests, blocks and would-blocks: per minute for the last 60 minutes, from the counter store (`hour`, the default), or per hour for the last 24 hours (`day`) or 7 days (`week`), from the hourly table |
| `GET api/rules/{id}/offenders?hour=current` | The clients the rule blocked most in the current or the previous hour (`previous`), by identity hash, and how many different clients it blocked |
| `GET api/health` | The health report, as `dynamic-throttle:health --json` gives it |
| `POST api/explain` | The explanation, as `dynamic-throttle:explain --json` gives it, with bearer tokens and header values masked |

**Changing** needs `manageDynamicThrottle`:

| Endpoint | Does | Audit action |
|---|---|---|
| `POST api/rules` | Creates a rule | `rule.create` |
| `PATCH api/rules/{id}` | Changes the fields it is given | `rule.update`, or `rule.enable` and `rule.disable` when only `enabled` changes |
| `DELETE api/rules/{id}` | Deletes a rule and the exemptions that name it | `rule.delete` |
| `POST api/exemptions` | Creates an exemption | `exemption.create` |
| `DELETE api/exemptions/{id}` | Deletes an exemption | `exemption.delete` |
| `PUT api/settings/bypass` | Turns bypass on (`for`, `forever`) or off, as the CLI does | `settings.bypass` |
| `PUT api/settings/scale` | Sets the scale factor (`for`, `forever`) or turns it off, as the CLI does | `settings.scale` |

- **Changes are checked by SPEC-018's validation** (422) and its confirmations (409 without `"confirm": true`), so a saved rule or exemption always compiles.
- **Each change writes one audit entry, in the same transaction** (ADR-0015 point 3): the actor, the action, the target (`rule:12`, `exemption:7`, `setting:bypass_all`), the fields that changed before and after, the client's IP and its user agent. The actor is the user as the user scope names one, `user:App\Models\User:42`, or `guest` where an undefined gate lets a guest in locally (SPEC-018). A change that changes nothing, such as bypass off when it is off, writes no entry and answers `"changed": false`.
- **A change answers with what it saved and what the compile did:** `"manifest": {"compiled": true, "version": 41}`, or `{"compiled": false, "error": "…"}` when the change is saved but the manifest could not be compiled, so servers keep the last one until the next compile (ADR-0007), as the CLI reports it (SPEC-013).
- **Explain takes its input in a POST body,** so a bearer token never lands in a URL, the browser's history or the web server's access log: `method`, `path`, `ip` (127.0.0.1 by default), `user`, `guard`, and `headers` as `["Authorization: Bearer …"]`. It writes nothing, so it needs only the view gate: the one POST that does (decision 2).
- **Explain masks secrets** (decision 3): an `api_token` or a `header` rule's identity shows only its last 4 characters, `token:…ab12`, and a value under 12 characters is hidden whole, `token:…`. The hash is shown in full, so it still ties to counters and events. Other identities show as given.
- **Errors are JSON.** The controllers' own errors (422, 409, 503) are JSON whatever the request accepts, and never a redirect. Errors raised before a controller runs (403, 404, 419, 429) follow Laravel's rule, JSON when the request asks for it (SPEC-018 AC-5), so a client sends `Accept: application/json`, as the dashboard's script will.

## Acceptance criteria

### Reading
- **AC-1** `GET api/rules` lists every rule, enabled or not, in evaluation order, 100 a page, with Laravel's page fields (`current_page`, `last_page`, `per_page`, `total`). Each rule has every field the API takes, its `id`, and `created_at` and `updated_at` in ISO 8601 UTC; enums are their values.
- **AC-2** `GET api/rules/{id}` shows one rule with the exemptions that name it. An unknown id, or one that is not a number, gets 404.
- **AC-3** `GET api/exemptions` lists every exemption, newest first, 100 a page, each with `active` false once it has expired. `?rule=12` lists only rule 12's.
- **AC-4** `GET api/settings` shows `{"bypass": {"on": true, "until": "2026-10-14T15:30:00+00:00"}, "scale": {"factor": "0.5", "until": null}}`. A setting that has ended shows off.
- **AC-5** `GET api/metrics` with requests recorded in the last hour gives 60 minutes, oldest first, each a UTC time, and for each rule with numbers, its `hits`, `blocks` and `would_blocks` per minute, 0 where it had none. A rule deleted since shows its id, with `name` null.
- **AC-6** `?period=day` gives the last 24 hours and `?period=week` the last 168, per hour, from the hourly table, the current hour included. Another period is refused with 422.
- **AC-7** `GET api/rules/{id}/offenders` gives the current hour's top clients by identity hash, most first, at most 100, with their counts, and the number of different clients; `?hour=previous` the hour before. On the cache driver, which keeps neither, both are null.
- **AC-8** `GET api/health` answers 200 with the report `dynamic-throttle:health --json` prints, its overall status inside, also when a check fails.
- **AC-9** `POST api/explain` with a method, a path, an IP, a user and headers answers what `dynamic-throttle:explain --json` prints for the same input, except for the masked values (AC-10).
- **AC-10** For an `api_token` rule, `Authorization: Bearer abcdefgh12345678` shows `token:…5678`, and `Bearer short` shows `token:…`; a `header` rule's value is masked the same way, and both hashes equal the CLI's. `ip:`, `user:` and `custom:` identities are not masked.
- **AC-11** Explain refuses a bad method, an IP that is not one, a guard without a user, a header without a colon, more than 20 headers, and a path over 2,048 characters, each with a 422 that names the field.

### Changing
- **AC-12** `POST api/rules` with a valid rule answers 201 with the rule and `"manifest": {"compiled": true, …}`, the manifest holds it, and one entry records `rule.create`, the actor, `rule:{id}`, no before, the rule as after, the IP and the user agent. An invalid rule answers 422, and nothing is saved or recorded.
- **AC-13** `PATCH api/rules/{id}` changes only the fields it is given, checked against the whole rule (SPEC-018), and records `rule.update` with the fields that changed, before and after. A patch of `enabled` alone records `rule.enable` or `rule.disable`, as the CLI does. A patch that changes nothing answers `"changed": false` and records nothing. Disabling or dry-running an enforcing rule without `confirm` answers 409 and changes nothing.
- **AC-14** `DELETE api/rules/{id}` of an enforcing rule without `confirm` answers 409. With it, the rule and the exemptions that name it are deleted, and one entry records `rule.delete`, the rule as before, with `exemptions_deleted` counting them, and no after.
- **AC-15** `POST api/exemptions` answers 201, the manifest holds the exemption, and one entry records `exemption.create`. `0.0.0.0/0` without `confirm` answers 409 (SPEC-018 AC-25).
- **AC-16** `DELETE api/exemptions/{id}` deletes it, with no confirm, and one entry records `exemption.delete` with the exemption as before.
- **AC-17** `PUT api/settings/bypass` and `api/settings/scale` take what the CLI takes (SPEC-018 AC-16), change the setting through the CLI's own code, and record `settings.bypass` or `settings.scale` with the user as actor. Bypass on and a factor below 1 need `confirm`. Turning off a setting that is off answers `"changed": false`.
- **AC-18** A change that is saved but whose compile fails answers 200, or 201, with `"manifest": {"compiled": false, "error": "…"}`: the change and its entry are kept.
- **AC-19** With the audit table missing, a change answers 503 with the message to publish and run the migrations, and nothing is saved. When the audit entry cannot be written, the change is rolled back.

### Access
- **AC-20** The authorization matrix holds for every endpoint, with both gates defined: a guest gets 403 everywhere; a viewer gets 200 on every read and on explain, and 403 on every change, with nothing saved; a manager gets every endpoint (the roadmap's Phase 4 exit).
- **AC-21** `POST api/explain` is the only route whose non-GET request needs only the view gate: a test lists the group's routes and finds no other. Every other POST, PUT, PATCH and DELETE needs `manageDynamicThrottle` (SPEC-018 AC-23).
- **AC-22** A 422 and a 409 are JSON with a `message` for a request that does not ask for JSON, and never a redirect. A change without a CSRF token gets 419, as SPEC-018 AC-6.

### Found while building
- **AC-24** In a dashboard request, the admin who sends it is logged in, and explain never takes them for the request it explains: without a user, or where the middleware would not see one, user rules count a guest, through the guard the request authenticated with and through `scopes.user.guards`, and a custom scope's `$request->user()` is null. With a user the middleware sees, that user counts. The admin is still logged in afterwards. The CLI, which has no logged-in user, was never affected.

### Hard rules
- **AC-23** The arch tests hold: the API's code is in `src/Dashboard`, outside the request path's namespaces, which never use it (SPEC-018 AC-19).

## Interfaces and files

- `src/Dashboard/DashboardRoutes.php`: the routes above, each with SPEC-018's middleware.
- `src/Dashboard/Authorize.php`: a third ability, `inspect`, for a POST that writes nothing: either gate, and only for POST (decision 2).
- `src/Dashboard/Api/`: `RuleController.php`, `ExemptionController.php`, `SettingController.php`, `MetricsController.php`, `OffendersController.php`, `HealthController.php`, `ExplainController.php`; `ApiErrors.php`, the controllers' JSON errors; `Changed.php`, a change's answer; and `Payloads.php`, how rules and exemptions are shown.
- `src/Support/Changes/Actor.php`: the actor, the IP and the user agent; `Actor::cli()` and `Actor::of(Request)`. `EmergencyControls` takes an `Actor`, so the CLI's entries are unchanged and the API's carry the IP and the user agent.
- `src/Support/EmergencyControls.php`: also creates, changes and deletes rules and exemptions, each with its entry in one transaction, and reads the compile back. It stays the only class that writes the audit log, as SPEC-013 AC-23's arch test holds, and `ChangeOutcome` carries the model a change saved.
- `src/Enums/AuditAction.php`: `rule.create`, `rule.update`, `rule.delete`, `exemption.create`, `exemption.delete`.
- `src/Support/Explanation/`: masking, applied by the API only; the CLI still prints identities as given (SPEC-014 decision 4).
- `docs/guide/dashboard.md`: the API's endpoints, with an example of each.
- Tests: `tests/Feature/Dashboard/Api/` (`RulesReadTest`, `RulesWriteTest`, `ExemptionsTest`, `SettingsTest`, `MetricsTest`, `HealthTest`, `ExplainTest`, `AuthorizationMatrixTest`, `ErrorsTest`), and `tests/Arch/ArchTest.php`.

## Out of scope

- Reading the audit log (`GET api/audit`), its filters and its pages (SPEC-019).
- The pages that use the API (SPEC-020 to SPEC-026), and the live blocks feed with raw IPs and user ids (SPEC-030).
- Editing an exemption: delete it and create another.
- API tokens: the group runs behind `dashboard.middleware`, which an app can set to its own stack, such as `['api', 'auth:sanctum']`.

## Edge cases and failure modes

- **Two admins change one rule at once:** the change reads the rule again, locked, validates the result against it, and saves and records it in one transaction, so the entry's before is the rule as the change found it.
- **The counter store is down:** `api/metrics?period=hour` and `api/rules/{id}/offenders` answer 503 with the store's error. `day` and `week` read the database and still answer. Explain shows the counters' error, as the CLI does.
- **The hourly table is missing:** `day` and `week` answer 503 with the message to publish and run the migrations.
- **A rule with many exemptions is deleted:** the entry counts them in `exemptions_deleted` instead of listing them, so it stays within the `before` column's size on MySQL. Each exemption's own creation is in the log.
- **A user agent longer than the request allows** is stored whole: the column is text (SPEC-013).
- **A guest in `local`** with no gate defined is recorded as `guest`, with its IP.
- **A rule id given to `?rule=`** that does not exist gives an empty page, not a 404.
- **The 500 enabled rules limit** applies to `POST` and `PATCH` (SPEC-018 AC-14).

## End-to-end verification

- `composer test -- --filter=Api`
- `composer test`, `composer analyse`
- In the Workbench app: create, patch and delete a rule with `curl`, a session cookie and the XSRF token, then read the audit table.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **REST paths under `{path}/api`, named `dynamic-throttle.api.*`,** internal until declared stable (ADR-0018 point 1), so their shape can still change before then.
2. **Explain is a POST that needs only the view gate** (the maintainer chose it on 2026-10-14): its input can hold a bearer token, which a GET would put in URLs and access logs, and support staff with the view gate need it. It is SPEC-018 AC-23's one exception, through an `inspect` ability that allows only POST, and a test proves no other route uses it. Rejected: POST for managers only, which leaves viewers without explain; GET, which logs tokens.
3. **Explain's answer masks bearer tokens and header values** to their last 4 characters, and hides values under 12 whole (the maintainer chose it on 2026-10-14). The hash stays whole. The CLI keeps showing them as given (SPEC-014 decision 4). Rejected: showing them as given, which puts them on screen and in screenshots.
4. **The API writes the audit entries of its own changes, in the same transaction,** from its first change endpoint. SPEC-019 reads them, and its roadmap line changes to say so. Rejected: endpoints first and entries later, which would leave changes unrecorded between the PRs, and break ADR-0015 point 3.
5. **The actor is `user:{class}:{id}`,** as exemptions and the user scope name users, so one user has one name in every table; `guest` for a guest.
6. **Settings change through `EmergencyControls`,** the CLI's own code, so both write the same entries.
7. **A patch of `enabled` alone records `rule.enable` or `rule.disable`,** as the CLI does, so the log names the same change the same way from either.
8. **Deleting a rule deletes the exemptions that name it,** as the table's foreign key does, and its entry counts them.
9. **Lists come 100 a page,** in Laravel's paginator format, which the pages can read without a library.
10. **Metrics come in three fixed periods,** an hour per minute, a day and a week per hour, which the Overview and Activity charts need. Custom ranges wait for a user who needs them.
11. **Offenders are identity hashes,** as the buffer keeps them (ADR-0024). Raw IPs and user ids come with SPEC-030's live feed.
12. **A change that saves but does not compile succeeds,** with the failure in `manifest`: it is saved and recorded, and the next compile picks it up, as the CLI reports it.
13. **Health always answers 200,** with the status inside, so the Overview can poll it and show a failing check.

## PRs

Stacked on each other, each green on its own (ADR-0027).

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-4/spec-017-1` | This spec, the roadmap's lines, SPEC-018 AC-23's note | Decisions |
| 2 | `phase-4/spec-017-2` | `Actor`, the JSON errors, and the settings endpoints | AC-4, AC-17, AC-19, AC-22 |
| 3 | `phase-4/spec-017-3` | The rules endpoints and their entries | AC-1, AC-2, AC-12 to AC-14, AC-18 |
| 4 | `phase-4/spec-017-4` | The exemptions endpoints and their entries | AC-3, AC-15, AC-16 |
| 5 | `phase-4/spec-017-5` | Metrics, offenders and health | AC-5 to AC-8 |
| 6 | `phase-4/spec-017-6` | Explain with masking, the authorization matrix, the guide, the changelog, the security review's fixes, and the spec's close | AC-9 to AC-11, AC-20, AC-21, AC-23, AC-24 |

## Open questions

None. Decisions 2 and 3 were asked first: one makes an exception to a security review's finding, the other was left to the maintainer by SPEC-014.
