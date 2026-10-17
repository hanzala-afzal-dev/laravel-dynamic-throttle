# SPEC-024: The Activity page

- Status: Approved
- ADRs: ADR-0013, ADR-0024, ADR-0028
- Roadmap phase: 5

## Problem

During an incident, the Overview says how much is blocked. It does not say who is blocked, from where, or on which route. The live feed (SPEC-030) keeps that for the last hour, and the offenders (SPEC-017) say which clients each rule blocks most. The dashboard links to an Activity page that is still a placeholder.

## Behavior

### The page, at `{path}/activity`
For viewers and managers alike; only "Exempt…" is a manager's.

- **The live feed,** from `GET api/feed`, newest first:
  - each entry: when (in the browser's zone, UTC on hover), "Blocked" or "Would block", the rule (a link to its page), the method and route, the client IP, and the user when there is one;
  - new entries arrive every 5 seconds while the tab is visible, asking only for what came since 2 seconds before the newest one shown (`since`), and adding only those it does not hold already: another worker or server can write a block a moment after a later one;
  - the page holds at most the newest 500;
  - "Pause" stops the polling, so a row stays put while someone reads it, and "Resume" fetches what came meanwhile;
  - filters, in the page: by outcome (all, blocked, would block) and by rule.
- **For a manager,** each entry's IP offers "Exempt…", which opens the Exemptions page with the add dialog filled with that IP and rule (SPEC-023).
- **When there is no feed,** as `api/feed` and the configuration (`api/config`, SPEC-031) tell:
  - a store that keeps none (`feed: null`): "This counter store keeps no live feed. Redis and Valkey do.";
  - the feed off, by `telemetry.live_feed` or `telemetry.enabled`: "The live feed is off: telemetry.live_feed in config/dynamic-throttle.php.";
  - `telemetry.sample_rate` below 1: a note that the feed shows about one block in N;
  - no block in the last hour: "Nothing was blocked in the last hour."
- **The offenders card:** a rule, chosen by name, and this hour or the last. It shows the clients the rule blocked most, by identity hash, with their counts, and how many different clients it blocked (`api/rules/{id}/offenders`). The rule page already shows these for one rule (SPEC-022); here, any rule is a click away.

## Acceptance criteria

### Frontend (Vitest)
- **AC-1** The feed lists each entry's time, outcome, rule (a link), method and route, IP and user, newest first. A deleted rule shows as "Rule #id (deleted)", without a link.
- **AC-2** It polls `api/feed?since={newest − 2000}` every 5 seconds, adds on top what it does not hold already, in time order, and keeps at most 500. Pause stops the polling and Resume restarts it.
- **AC-3** The outcome and rule filters narrow the list without asking the API.
- **AC-4** A store without a feed, the feed off, a sample below 1, and an hour without a block each show their message.
- **AC-5** For a manager, "Exempt…" opens `/exemptions` with the add dialog filled with the entry's IP and rule. A viewer sees no "Exempt…".
- **AC-6** The offenders card asks `api/rules/{id}/offenders` for the chosen rule and hour, and shows the hashes, their counts and the number of clients, or that the store keeps none.

## Interfaces and files

- `resources/dashboard/pages/activity/`: `index.tsx`, `feed-card.tsx`, `offenders-card.tsx`, and their tests.
- `resources/dashboard/lib/feed.ts`: the feed's type, and its polling with `since`.
- `resources/dashboard/pages/exemptions/`: the add dialog opens filled from the URL's `exempt` and `rule`.
- `resources/dashboard/pages/index.tsx`: the route.

## Out of scope

- Blocking a client from the dashboard: the package limits; blocking belongs to a firewall.
- Searching more than the last hour, or exporting the feed.
- Exempting a user from an entry: the add dialog takes a user by hand.

## Edge cases and failure modes

- **The tab is hidden:** polling stops, and the first poll after it returns asks for everything since the newest entry shown.
- **More than 500 blocks between two polls:** the API answers the newest 500, and the older ones between are not shown. The page holds 500 at most anyway.
- **The counter store fails:** a poll answers 503. The page shows the error above the entries it holds, and keeps polling.
- **A rule is deleted:** its entries stay, as "Rule #id (deleted)", until they expire.
- **A block is written late,** by a slower worker or a server whose clock is behind: the 2 seconds of overlap catch it. One later than that is missed until the page reloads.
- **Two identical entries,** the same client blocked by the same rule in the same millisecond: the page counts how many of each it holds, so it adds the second and never a copy of the first.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test`, `composer analyse`
- In the Workbench app on Redis: run `demo:traffic`, open Activity, and see blocks arrive; pause, filter, and exempt an IP from an entry.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The page asks only for what is new,** with `since`, and keeps the newest 500 in the browser, so a page left open costs one small request every 5 seconds. Each request overlaps the last by 2 seconds, so a block written a moment late still shows (from SPEC-030's review).
2. **Filters run in the page,** since it holds at most 500 entries.
3. **"Exempt…" opens the Exemptions page's dialog,** rather than adding from Activity, so the confirmation for wide ranges and the audit entry stay in one place.
4. **The offenders stay hashes** (ADR-0024); the feed beside them shows the raw IPs of the last hour.
5. **Whether the feed is on, and the sample rate, come from `api/config`,** which viewers read too (SPEC-031), so `api/feed` keeps the shape SPEC-030 gave it.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-024-1` | This spec | Decisions |
| 2 | `phase-5/spec-024-2` | The feed card, its polling, filters and pause | AC-1 to AC-4 |
| 3 | `phase-5/spec-024-3` | "Exempt…", the offenders card, the guide, the changelog and the spec's close | AC-5, AC-6 |

## Open questions

None.
