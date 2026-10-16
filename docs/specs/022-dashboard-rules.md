# SPEC-022: The Rules pages

- Status: Done
- ADRs: ADR-0012, ADR-0015, ADR-0024, ADR-0028
- Roadmap phase: 5

## Problem

Rules are the package's reason to exist, and the dashboard cannot show or change one yet. An admin needs to:
- find a rule among hundreds;
- see what it does and what it did;
- create one without knowing every field;
- try it in dry-run, then promote it with evidence;
- switch it off fast, or delete it.

The JSON API already does each change, with its validation, its confirmations and its audit entry (SPEC-017, SPEC-018). Only a search over the list is missing.

## Behavior

### The list, at `{path}/rules`
- **The rules in evaluation order** (priority, then id), 100 a page, from `GET api/rules`. Each row shows:
  - the name, a link to the rule's page;
  - what it applies to: the method and the pattern, such as `POST login`;
  - its scope, such as "IP" or "Header X-Tenant";
  - its limit, such as "5 per minute";
  - "Dry-run" or "Enforcing";
  - "Enabled" or "Disabled";
  - its blocks, or would-blocks, in the last hour, from `api/metrics`.
- **Search and filters:** a search box matches the name or the pattern. Filters choose enabled or disabled, and enforcing or dry-run. They go to the API as `search`, `enabled` and `mode`, and into the page's URL, so a filtered list can be shared and survives a reload.
- **For a manager:**
  - "New rule";
  - a switch in each row that enables or disables the rule;
  - Edit and Duplicate buttons in each row. Delete is on the rule's page, away from the switches.
- **No rule yet:** the page says so, and offers "New rule" to a manager.

### The form, at `{path}/rules/new` and `{path}/rules/{id}/edit`
- **Templates,** for a new rule: Login, Password reset, Sign-up, API per token, API per user, Expensive endpoint, or Blank. A template fills the form, which the admin then changes as they like.
- **The fields,** each with a short hint:
  - name and description;
  - method and pattern;
  - scope, with the header's name for the header scope and the registered scope for the custom one;
  - the limit: attempts per window, the window as a number and a unit (seconds, minutes, hours or days);
  - priority;
  - mode;
  - on failure, and on a missing identity;
  - the 429 response's JSON body, optional, such as `{"message": "Too many login attempts."}`, checked as JSON before saving;
  - enabled.
- **A new rule starts in dry-run,** whatever the template, so it shows what it would block before it blocks anything (decision 2).
- **Saving:**
  - A new rule sends `POST api/rules`. An edit sends `PATCH api/rules/{id}` with only the fields that changed.
  - A 422 shows each message under its field. A 409 asks to confirm, as on the Overview (SPEC-021 decision 8).
  - After saving, the page goes to the rule's page.
- **Duplicate** opens the form filled from a rule, named "Copy of {name}", in dry-run.

### A rule's page, at `{path}/rules/{id}`
- **Its settings,** as the form names them, and when it was created and last changed.
- **Its traffic:** the chart of SPEC-021 for this rule alone, over an hour, a day or a week.
- **Its offenders:** the clients it blocked most this hour or the last, by identity hash (ADR-0024), with their counts and how many different clients it blocked. On a store that keeps none, the card says so.
- **Its exemptions:** the newest 100, with a link to the Exemptions page.
- **Its history:** its audit entries, newest first, from `api/audit?target=rule:{id}`, with what each changed.
- **For a manager:** Edit, Duplicate, Enable or Disable, Delete, and for a dry-run rule, Promote.
- **Promote** shows the rule's last 24 hours: its checks, and how many it would have blocked, with their share. "Enforce" then sends `mode: enforce`.
- **A rule that does not exist,** or was deleted, shows "There is no rule {id}" with a link to the list.

### Deleting
Deleting always asks first. The dialog names the rule and says that its exemptions go with it. For an enabled, enforcing rule it also says that the rule stops limiting requests, and "Delete" then sends `confirm: true`, since the person has just confirmed that exact deletion (decision 6). For another rule it sends no `confirm`, so the API still asks if another admin has enforced it since.

### `GET api/rules` filters
- `search` matches the name or the pattern, ignoring case, without the spaces around it. `%`, `_` and `\` match themselves on every database. A search of spaces alone lists every rule. `enabled` takes `true` or `false`, and `mode` takes `enforce` or `dry_run`.
- A filter with another value answers 422.
- The order and the 100 a page stay.

## Acceptance criteria

### Server (Pest)
- **AC-1** `GET api/rules?search=log` lists the rules whose name or pattern holds "log", ignoring case. `enabled=false` lists the disabled ones and `mode=dry_run` the dry-run ones. The filters combine, and keep the order and the pages. An unknown value of `enabled` or `mode` answers 422.

### Frontend (Vitest)
- **AC-2** The list shows each rule's name as a link, method and pattern, scope, limit, mode, status and last hour's blocks or would-blocks, in evaluation order.
- **AC-3** Typing in the search box, after a short pause, and choosing a filter ask `api/rules` with `search`, `enabled` and `mode`, and put them in the URL. Opening that URL applies them.
- **AC-4** For a manager, a row's switch sends `PATCH api/rules/{id}` with `enabled`. A 409 asks to confirm. "New rule", Edit and Duplicate link to the form. A viewer sees no switch, no button and no "New rule".
- **AC-5** Choosing a template fills the form, in dry-run. Saving a new rule sends `POST api/rules` with the fields, the window in seconds, and goes to the new rule's page.
- **AC-6** A 422 shows each field's message under it. A 409 shows its message in a dialog and resends with `confirm: true`.
- **AC-7** Editing sends only the fields that changed. Duplicate fills the form from the rule, named "Copy of {name}", in dry-run.
- **AC-8** A rule's page shows its settings, its chart, its offenders this hour and the last, its exemptions and its history. On a store without offenders, it says so.
- **AC-9** Promote shows the last 24 hours' checks and would-blocks with their share, and "Enforce" sends `mode: enforce`.
- **AC-10** Delete asks first, naming the rule, its exemptions, and for an enforcing rule that it stops limiting. "Delete" sends `DELETE api/rules/{id}` with `confirm: true` and goes back to the list.
- **AC-11** A rule that does not exist shows "There is no rule {id}", with a link to the list.

### From the review
- **AC-12** An edit compares with the rule as the form opened it. A refetch, or another admin's change to a field this person left alone, is never sent back.
- **AC-13** A rule saved with an empty 429 body, `{}`, comes back as `{}`, and the form edits it like any other. The form also reads `[]` as `{}`.
- **AC-14** Delete sends `confirm: true` only when its dialog said the rule stops limiting. For another rule it sends none, and a 409 shows its message in a dialog to confirm.
- **AC-15** A change on a rule's page refreshes its history. A change answered 404 shows "There is no rule {id}".
- **AC-16** The search ignores the spaces around it, and spaces alone search nothing. `%`, `_` and `\` match themselves, on SQLite, MySQL and PostgreSQL. The search box takes at most 100 characters.
- **AC-17** Opening the list without a search, as the sidebar's link does, empties the search box. A page past the last goes to the last.
- **AC-18** The window rounds to whole seconds, and its hint says it is at most a day. `/rules/{id}/edit` with an id that is not a number says the page is not found. A field whose error replaces its hint points at the error alone.

## Interfaces and files

- `src/Dashboard/Api/RuleController.php`: the filters in `index()`.
- `resources/dashboard/pages/rules/`: `list.tsx`, `rule.tsx`, `form.tsx`, `templates.ts`, and their tests.
- `resources/dashboard/pages/overview/traffic-card.tsx`: the chart of SPEC-021 takes a `ruleId`, to show one rule alone. `components/confirm-dialog.tsx` and `lib/confirmed-change.ts` are shared with the Overview.
- `resources/dashboard/components/ui/`: shadcn/ui's `switch` and `textarea`.
- `tests/Drivers/RuleSearchTest.php`: the search on MySQL and PostgreSQL (AC-16).

## Out of scope

- Bulk actions, CSV export, and importing or exporting rules (after 0.1.0, ADR-0028).
- Reordering rules by dragging: priority is a number in the form.
- Editing an exemption from the rule's page (SPEC-023).

## Edge cases and failure modes

- **Two admins edit one rule:** an edit sends only what it changed, so it never undoes the other's change to another field. The last save of a field wins, and both are in the history.
- **500 rules are enabled:** enabling one more answers 422 with the API's message, shown under the switch or the field.
- **A custom scope the app no longer registers:** the rule's page shows its name, and saving answers 422 until the scope is registered or changed.
- **The audit table is missing:** the history card shows the API's 503 message, and the rest of the page still shows.
- **A deleted rule's page is open:** the next action answers 404, and the page says there is no such rule (AC-15).
- **Another admin changes a rule while its form is open:** the form keeps what it opened with, and sends only what this person changed (AC-12).
- **Another admin enforces a rule while its delete dialog is open:** the dialog sent no `confirm`, so the API asks again (AC-14).

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test -- --filter=Rules`, `composer test`, `composer analyse`
- In the Workbench app:
  - create a rule from the Login template, in dry-run;
  - send requests and see its would-blocks;
  - promote it, and see it block;
  - disable it, with the confirmation, then delete it;
  - check the console shows no CSP error.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The search runs on the server,** with `search`, `enabled` and `mode` on `GET api/rules`. The list pages by 100, so the browser never holds every rule to filter them.
2. **A new rule starts in dry-run,** from a template or blank, and so does a duplicate. Promote then enforces it with its evidence in view (ADR-0012, the README's "Dry-run first").
3. **The templates live in the page,** as starting values, not in the database or the API. Saving one is an ordinary new rule.
4. **The window is a number and a unit;** the API still takes seconds.
5. **An edit sends only what changed,** so two admins editing different fields never undo each other's change.
6. **Deleting always asks first in the page,** since it cannot be undone. When the dialog said the rule stops limiting, it then sends `confirm: true`: the person has confirmed that exact deletion. Otherwise it sends none, so the API still asks if that changed (AC-14). Other changes send no `confirm` until the API asks (SPEC-021 decision 8).
7. **Offenders stay hashes** (ADR-0024). The live feed of SPEC-030 shows the raw IPs and user ids for an hour.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-022-1` | This spec | Decisions |
| 2 | `phase-5/spec-022-2` | The API's filters, and the list with search, filters and the switch | AC-1 to AC-4 |
| 3 | `phase-5/spec-022-3` | The form, the templates, duplicate and the confirmation | AC-5 to AC-7 |
| 4 | `phase-5/spec-022-4` | A rule's page: settings, chart, offenders, exemptions, history | AC-8, AC-11 |
| 5 | `phase-5/spec-022-5` | Promote and delete, the guide, the changelog, the review's fixes and the spec's close | AC-9, AC-10, AC-12 to AC-18 |

## Open questions

None.
