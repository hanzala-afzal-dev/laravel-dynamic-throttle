# SPEC-021: The Overview and the emergency controls

- Status: Approved
- ADRs: ADR-0013, ADR-0015, ADR-0028
- Roadmap phase: 5

## Problem

The dashboard opens on a placeholder. An operator who opens it during an incident needs to see at once:
- whether limiting works;
- what the rules blocked in the last hour, and how that compares with the last day or week;
- which rules block most;
- whether bypass or a scale factor is on.

They also need to turn bypass or a scale factor on or off without a terminal. The API serves all of this (SPEC-017), except the rule counts.

## Behavior

The Overview is the dashboard's first page, at `{path}`. It loads its own code on demand (ADR-0028 point 5).

### The cards along the top
- **Health,** from `api/health`:
  - "Healthy", "Warnings" or "Errors", from the report's status;
  - up to 3 checks that are not ok, with their messages;
  - a link to Settings for the whole report, and a "Check again" button.
- **Rules,** from a new `GET api/rules/counts`: how many are enabled, split into enforcing and dry-run, and how many are disabled. It links to Rules.
- **Blocks in the last hour:** the 429s every enforcing rule answered, with their share of the rules' checks.
- **Would-blocks in the last hour:** the requests the dry-run rules would have blocked.

Numbers use the browser's locale. The last hour's cards read `api/metrics?period=hour`, whatever period the chart shows.

### Traffic
- **The chart.** Tabs for the last hour, day or week read `api/metrics?period=hour`, `day` or `week`. The chart stacks, per minute or per hour, the blocks and the would-blocks that every rule counted. Its tooltip shows the time in the browser's zone and in UTC, and that step's checks.
- **Above the chart,** a sentence gives the period's totals: checks, blocks with their share, and would-blocks.
- **No numbers:** "No rule checked a request in the last hour." (or day, or week).
- **The counter store down or the hourly table missing:** the API answers 503, and the card shows its message. The other cards still show.

### Top rules
- The 5 rules with the most blocks in the chart's period, then the most would-blocks. Each row shows the checks, the blocks and the would-blocks, and links to the rule's page.
- A rule deleted since shows as "Deleted rule #12", with no link.
- None blocked or would have: "No rule blocked a request in the last hour."

### Emergency controls
- **Bypass,** for a manager:
  - Off: a duration (15 minutes, 1 hour by default, 4 hours, 1 day, or no end time) and "Turn on bypass", which sends `PUT api/settings/bypass` with `state: on` and `for`, or `forever: true`.
  - On: when it ends, and "Turn off bypass".
- **The scale factor,** for a manager:
  - Off: the factor, as the CLI takes it (0.1 to 10), the same durations, and "Apply", which sends `PUT api/settings/scale`.
  - On: the factor, when it ends, and "Turn off scaling".
- **Confirmations.** The page sends a change without `confirm`. When the API answers 409, its message shows in a dialog. "Confirm" sends the change again with `confirm: true`; "Cancel" sends nothing.
- **Errors.** A 422 shows its message under the field; other errors show under the control.
- **After a change,** the settings refresh, so the controls and the banner on every page (SPEC-020) show the new state at once.
- **A viewer** sees the state of both, and no control that changes them.

### Polling
- The last hour's metrics and the settings poll every 5 seconds (SPEC-020).
- The day's and the week's metrics, the health report and the rule counts refresh every minute.
- Nothing polls while the tab is hidden.

### `GET api/rules/counts`
- Answers `{"enforcing": 3, "dry_run": 1, "disabled": 2}`: enabled rules by mode, and disabled rules. One grouped query.
- Needs either gate, like every read (SPEC-018), and is named `dynamic-throttle.api.rules.counts`.

## Acceptance criteria

### Server (Pest)
- **AC-1** `GET api/rules/counts` answers the enabled rules by mode and the disabled rules, to a viewer, with 0 for a kind that has none, in one query. A guest in production gets 403.
- **AC-2** The bundle budget counts the Overview's code with the entry's, since it is the first page; CI fails above 300 KB gzipped.

### Frontend (Vitest)
- **AC-3** The health card shows "Healthy", "Warnings" or "Errors" from the report's status, lists up to 3 checks that are not ok with their messages, and links to Settings. "Check again" asks `api/health` again.
- **AC-4** The rules card shows the enabled rules with the enforcing and dry-run counts, and the disabled count, and links to Rules.
- **AC-5** The last hour's cards sum every rule's blocks and would-blocks over the 60 minutes, with the blocks' share of the checks, in the browser's locale.
- **AC-6** The chart's tabs ask `api/metrics` for `hour`, `day` or `week`. The sentence above the chart gives that period's totals. With no numbers, the card says no rule checked a request in that period.
- **AC-7** The top rules are the 5 with the most blocks, then the most would-blocks, each a link to `/rules/{id}`. A deleted rule shows "Deleted rule #{id}" with no link. With none, the card says so.
- **AC-8** A 503 from `api/metrics` shows its message in the traffic card and in the top rules card, and the health and rules cards still show.
- **AC-9** For a manager with bypass off, "Turn on bypass" sends `state: on, for: 1h`. Choosing "No end time" sends `forever: true` instead. The 409's message shows in a dialog: "Confirm" sends the same change with `confirm: true`, and "Cancel" sends nothing more. With bypass on, the card shows its end, and "Turn off bypass" sends `state: off`.
- **AC-10** For a manager, "Apply" sends the typed factor and the duration to `api/settings/scale`. A 409 asks to confirm, as for bypass. A 422 shows its message under the factor. With a factor on, the card shows the factor and its end, and "Turn off scaling" sends `factor: off`.
- **AC-11** After a change succeeds, `api/settings` is asked again.
- **AC-12** A viewer sees whether bypass and scaling are on, and until when, and no button or field that changes them.
- **AC-13** The hour's metrics and the settings poll every 5 seconds; the day's and the week's metrics, the health report and the rule counts every 60 seconds.

## Interfaces and files

- `src/Dashboard/Api/RuleController.php`: `counts()`. `DashboardRoutes.php` registers the route, before `api/rules/{rule}`.
- `resources/dashboard/pages/overview/`: the page and its cards (`health-card.tsx`, `rules-card.tsx`, `traffic-card.tsx`, `top-rules-card.tsx`, `emergency-controls.tsx`), with their tests.
- `resources/dashboard/lib/metrics.ts`: the sums and the top rules, from `api/metrics`.
- `resources/dashboard/components/ui/`: shadcn/ui's `chart`, `tabs`, `alert-dialog`, `input`, `label`, `native-select`, `table` and `skeleton`.
- `package.json`: `recharts`.
- `scripts/dashboard-budget.mjs`: counts the Overview's chunk.

## Out of scope

- The whole health report, with how to fix each check (SPEC-031).
- A rule's own chart and offenders (SPEC-022).
- The live feed of recent blocks (SPEC-030, SPEC-024).
- Choosing a time range other than the last hour, day or week.

## Edge cases and failure modes

- **A request that two rules check** counts in both rules' numbers, so the totals add up checks, not requests. The cards say "checks".
- **Sampling below 1** scales the numbers up (SPEC-015), so they are estimates. The health report's sampling check already says so.
- **A rule deleted in the period** keeps its numbers, with its name null, as "Deleted rule #id".
- **Bypass ends while the page is open:** the next poll shows it off, and the controls offer to turn it on again.
- **Two managers change bypass at once:** each change is recorded (SPEC-017). After a change, the page shows whatever state the server then holds.
- **The settings table is missing:** the settings and the changes answer 503 with the message, and the controls show it.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test -- --filter=Counts`, `composer test`, `composer analyse`
- In the Workbench app (`composer serve`):
  - send requests through a rule, and see the chart and the top rules;
  - turn bypass on with the dialog, and see the banner;
  - turn it off;
  - check the browser's console shows no CSP error.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **A new `GET api/rules/counts`** gives the rule counts in one grouped query, rather than paging through every rule in the browser.
2. **The totals sum the rules' numbers,** so a request that two rules check counts twice. The page says "checks", not "requests", as the telemetry guide does.
3. **The chart stacks blocks and would-blocks,** which never overlap: a rule either enforces or runs in dry-run. Checks are usually far larger, so they appear in the sentence and the tooltip, not as a series that would flatten the others.
4. **Each kind of data refreshes at its own pace:**
   - every 5 seconds: the counter store's hour and the settings;
   - every minute: the day and the week, which the scheduler moves into the hourly table once a minute, the health report, which runs every check, and the rule counts.
5. **Recharts, through shadcn/ui's chart component.** Its `<style>` element carries the page's nonce, so the strict CSP holds (SPEC-020 decision 3).
6. **The budget counts the Overview's code.** The Overview loads on demand, as every page does, but it is the first page anyone sees.
7. **Durations are offered as choices:** 15 minutes, 1 hour (the CLI's default), 4 hours, 1 day, or no end time. The factor is typed, as the CLI takes it.
8. **The server decides what needs confirming.** The page sends without `confirm` and shows the 409's own message, so the dashboard and the API can never disagree on what is dangerous.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-021-1` | This spec | Decisions |
| 2 | `phase-5/spec-021-2` | `api/rules/counts`, pages loaded on demand, the budget, and the health and rules cards | AC-1 to AC-4 |
| 3 | `phase-5/spec-021-3` | Recharts, the last hour's cards, the traffic chart and the top rules | AC-5 to AC-8, AC-13 |
| 4 | `phase-5/spec-021-4` | The emergency controls, the guide, the changelog and the spec's close | AC-9 to AC-12 |

## Open questions

None.
