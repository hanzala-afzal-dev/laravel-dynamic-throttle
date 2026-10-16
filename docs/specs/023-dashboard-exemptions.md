# SPEC-023: The Exemptions page

- Status: Done
- ADRs: ADR-0015, ADR-0028
- Roadmap phase: 5

## Problem

An exemption lets a client through one rule or every rule: the office's IP range, a partner's user, a load test. Today the dashboard links to an Exemptions page that is a placeholder. An admin needs to:
- see who is exempt from what, and until when;
- find the expired ones apart from the active ones;
- add one for a while, without working out a date;
- remove one.

The JSON API already does each change, with its validation, its confirmation for wide IP ranges and its audit entry (SPEC-017, SPEC-018). Only the list's filters are missing.

## Behavior

### The list, at `{path}/exemptions`
- **The exemptions, newest first,** 100 a page, from `GET api/exemptions`. Each row shows:
  - who: "IP 203.0.113.0/24", "User 42", or "Tenant acme" for a custom subject, with its scope's name;
  - which rule: a link to it, or "Every rule";
  - the reason;
  - when it ends: a time in the browser's zone, "Never", or "Ended …" for an expired one;
  - when it was added.
- **Filters:** state (active, expired or all; active by default), subject (IP, user or custom) and rule (one rule, or the exemptions on every rule, or all). They go to the API as `state`, `subject` and `rule`, and into the page's URL. A rule's page links here with `?rule={id}` (SPEC-022).
- **Pages:** 100 exemptions a page. A page past the last, from an old link or after exemptions ended, goes to the last, as on the rules list (SPEC-022 AC-17).
- **For a manager:** "Add exemption", and Remove on each row.
- **None:** the page says so.

### Adding
- **The form, in a dialog:**
  - who: IP or range, user, or custom with the scope's name;
  - the value: an IP or CIDR range, a user's id, or the custom scope's value;
  - the rule: every rule, or one rule, chosen by name; the rule the list shows, by default;
  - how long: 1 hour, 1 day (the default), 7 days, 30 days, or no end;
  - an optional reason.
- **Saving** sends `POST api/exemptions`. The end time is computed in the browser as now plus the chosen length, in UTC.
- **The API decides:**
  - a 422 shows under its field;
  - a range wider than a /8 or a /32 answers 409, whose message shows in a dialog to confirm (SPEC-021 decision 8);
  - a user id alone is the app's default guard's user (SPEC-017 AC-25).

### Removing
- Remove asks first: "Remove the exemption for IP 203.0.113.10 on every rule? Its requests are limited again." Then it sends `DELETE api/exemptions/{id}`.

### `GET api/exemptions` filters
- `state` takes `active` or `expired`. An exemption is active until its end time, and always without one.
- `subject` takes `ip`, `user` or `custom`.
- `rule` takes a rule's id, as today, or `none` for the exemptions on every rule.
- An unknown value answers 422. The order and the 100 a page stay, and the pages' links keep the filters.

## Acceptance criteria

### Server (Pest)
- **AC-1** `GET api/exemptions?state=active` lists the active exemptions, `state=expired` the ended ones, `subject=user` the user ones, and `rule=none` those on every rule. The filters combine with `rule={id}` and keep the order and the pages. An unknown `state`, `subject` or `rule` answers 422.

### Frontend (Vitest)
- **AC-2** The list shows who, which rule (a link, or "Every rule"), the reason, the end ("Never", a time, or "Ended …") and when each was added, newest first.
- **AC-3** The filters ask `api/exemptions` with `state`, `subject` and `rule`, and put them in the URL; opening `?rule=3` applies it. The state starts at active. The pages keep the filters, and a page past the last goes to the last.
- **AC-4** For a manager, adding an IP exemption for 7 days on every rule sends `POST api/exemptions` with `subject: ip`, the value, `rule_id: null`, the reason, and `expires_at` seven days from now. "No end" sends `expires_at: null`. A 422 shows under its field.
- **AC-5** A 409 shows its message in a dialog, and Confirm sends the same exemption with `confirm: true`.
- **AC-6** Remove asks first, naming who and which rule, and then sends `DELETE api/exemptions/{id}`. Cancel sends nothing.
- **AC-7** A viewer sees no "Add exemption" and no Remove.

## Interfaces and files

- `src/Dashboard/Api/ExemptionController.php`: the filters in `index()`.
- `resources/dashboard/pages/exemptions/`: `list.tsx`, `add-dialog.tsx`, `rule-name.tsx`, and their tests.
- `resources/dashboard/lib/exemptions.ts`: the types, the query and the labels. `components/exemption-who.tsx` shows who an exemption lets through, on this page and a rule's.
- `resources/dashboard/components/ui/dialog.tsx`: shadcn/ui's dialog, for the add form.

## Out of scope

- Editing an exemption: remove it and add another, which the audit log records as two changes.
- Exempting by header or API token. The engine exempts by IP, user and custom subjects only (SPEC-009).
- Bulk add or remove, and import or export (after 0.1.0, ADR-0028).

## Edge cases and failure modes

- **An exemption ends while the page is open:** the next refresh shows it under expired.
- **A rule is deleted:** its exemptions go with it (SPEC-017), so the list never shows an exemption for a missing rule.
- **1,000 exemptions:** the manifest's health check warns (SPEC-016); the list pages through them all.
- **A custom scope the app no longer registers:** adding one answers 422 with the API's message.
- **The browser's clock is wrong:** the end time it computes is off by as much. The API refuses an end in the past.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test -- --filter=Exemption`, `composer test`, `composer analyse`
- In the Workbench app:
  - exempt an IP for an hour from the Orders rule;
  - send traffic from it, and see none blocked;
  - remove it;
  - try `0.0.0.0/0`, see the confirmation, and cancel.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The filters run on the server,** with `state`, `subject` and `rule=none` on `GET api/exemptions`, as the rules' do (SPEC-022 decision 1).
2. **The list opens on the active exemptions,** since expired ones change nothing. All and expired are one click away.
3. **Lengths are choices,** 1 hour to 30 days or no end, as for bypass (SPEC-021 decision 7), and 1 day by default. The end time is computed in the browser at the moment of saving.
4. **No edit.** Remove and add again, so each change stays one plain entry in the audit log.
5. **Remove asks first in the page,** as deleting a rule does (SPEC-022 decision 6). The API asks for no confirmation, since removing an exemption only limits more.
6. **The rules' names come from `api/rules`,** and the exemption's payload stays as SPEC-017 gives it. The first 100 rules, in evaluation order, are the filter's and the form's choices. A rule beyond them, in a row or in the URL, is asked for by its id.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-023-1` | This spec | Decisions |
| 2 | `phase-5/spec-023-2` | The API's filters, and the list with its filters | AC-1 to AC-3 |
| 3 | `phase-5/spec-023-3` | Add and remove, the guide, the changelog and the spec's close | AC-4 to AC-7 |

## Open questions

None.
