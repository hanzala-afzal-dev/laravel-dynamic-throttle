# SPEC-019: Reading the audit log

- Status: Done
- ADRs: ADR-0015, ADR-0018
- Roadmap phase: 4

## Problem

Every change from the CLI (SPEC-013) and the JSON API (SPEC-017) writes an entry in `dynamic_throttle_audit_log`, but nothing reads it back, so "who disabled the login rule, and when?" takes a database client. The dashboard's Audit log page (SPEC-025) needs an endpoint to read from.

## Behavior

- **`GET {path}/api/audit`** lists the entries, newest first, 100 a page, with a cursor to the next page. It needs either gate (decision 1).
- **Each entry** shows its `id`, `actor`, `action`, `target`, `before` and `after` as written, `ip`, `user_agent`, and `created_at` in ISO 8601 UTC.
- **Filters**, each optional, combined: `action` (one of the actions), `target` (such as `rule:12`), `actor` (such as `cli:deploy` or `user:App\Models\User:42`), and `from` and `until` (ISO 8601 times, both included).
- **Reading changes nothing.** A read-only model reads the table and refuses to write, so `EmergencyControls` stays the only writer (SPEC-013 AC-23, decision 4).
- **Every change writes exactly one entry** (the roadmap's Phase 4 exit): each change endpoint of the API is tested to write one, naming what it changed, and none when it changes nothing.

## Acceptance criteria

- **AC-1** `GET api/audit` lists entries newest first, 100 a page, in Laravel's cursor paginator format (`data`, `next_cursor`, `per_page`). Each entry has every field above; `before` and `after` come back as the objects written, or null.
- **AC-2** `?cursor=` with the `next_cursor` of a page gives the next one; the last page's `next_cursor` is null. With 101 entries, the second page holds the oldest.
- **AC-3** The filters narrow the list and combine: `action=rule.disable`, `target=rule:12`, `actor=cli:deploy`, `from` and `until`. An unknown action, a `from` that is not a time, a cursor that is not one, and an `until` before `from` answer 422 naming the field.
- **AC-4** A viewer and a manager get 200 and a guest 403 (SPEC-017 AC-20's matrix gains the route). Reading writes nothing: the table's count is the same after.
- **AC-5** The read-only model refuses to create, change and delete an entry, with a `LogicException`; the arch test that only `EmergencyControls` uses `AuditEntry` holds as it is.
- **AC-6** Every change route of the API, listed from the router so a new one cannot be missed, writes exactly one entry whose action and target name what it changed; the same request sent again where it changes nothing writes none.
- **AC-7** With the audit table missing, `GET api/audit` answers 503 with the message to publish and run the migrations.

### From the security review
A review subagent read the spec's code before the Phase 4 exit. Its findings, each fixed test-first:
- **AC-8** A cursor whose `id` is not a whole number of 1 or more answers 422, never a 500; the cursor is read from the query string only, never from a JSON body; an entry whose user agent is not UTF-8 is listed with the bad bytes replaced; and a `from` or `until` past the year 9998, which SQLite would compare wrongly as text, answers 422.

## Interfaces and files

- `src/Models/AuditLogEntry.php`: the read-only model of `dynamic_throttle_audit_log`.
- `src/Dashboard/Api/AuditController.php`, and `DashboardRoutes.php`: `GET api/audit`, named `dynamic-throttle.api.audit`.
- `docs/guide/dashboard.md`: the endpoint, in the API's table.
- Tests: `tests/Feature/Dashboard/Api/AuditLogTest.php` (AC-1 to AC-5, AC-7, AC-8), `OneEntryPerChangeTest.php` (AC-6), and a row in `AuthorizationMatrixTest.php`.

## Out of scope

- The Audit log page (SPEC-025).
- Pruning old entries: the log keeps every entry (decision 5).
- Showing a user's name instead of `user:Class:id`: the page can look users up (SPEC-025).

## Edge cases and failure modes

- **An entry written by hand** with `before` that is not JSON shows it as null, as the model's cast reads it.
- **A large log:** cursor pages read by id, so a page costs the same at any depth, and no page counts the table. `target` and `created_at` have indexes; `actor` and `action` filters scan until a page fills.
- **A cursor from another filter** pages from that entry on, with the new filters: Laravel's cursor holds the last id only.

## End-to-end verification

- `composer test -- --filter=Audit`
- `composer test`, `composer analyse`

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **Viewers read the log,** with either gate: "who changed this, and when?" is what support staff ask during an incident (ADR-0015). It shows admins' IPs and user agents; an app that should hide them from support staff gives the view gate to admins only.
2. **Cursor pages, 100 each, newest first,** in Laravel's format: the log only grows, and counting it for page numbers gets slow. Rejected: numbered pages, as rules and exemptions have, which are small tables.
3. **Exact filters,** on action, target, actor and a time range, which cover "what happened to rule 12", "what did Alice change" and "what changed during the incident". Free-text search waits for a user who needs it.
4. **A read-only model, `AuditLogEntry`, reads the table,** and `AuditEntry` stays the writer's model, so SPEC-013 AC-23's arch test holds unchanged. Rejected: reading through `AuditEntry`, which needs that arch test widened.
5. **No pruning:** the log is append-only (ADR-0015 point 3). An app that must expire entries deletes them in its own job. Revisit when a user asks.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-4/spec-019-1` | This spec and the roadmap's line | Decisions |
| 2 | `phase-4/spec-019-2` | The model, the endpoint, the one-entry-per-change test, the guide, the changelog, the security review's fixes, and the spec's close | AC-1 to AC-8 |

## Open questions

None.

## Close

Done on 2026-10-15. Every AC has a test. AC-6, one entry per change across every change route of the API, passed from the start: SPEC-017 built it, and this spec pins it down for the Phase 4 exit.
