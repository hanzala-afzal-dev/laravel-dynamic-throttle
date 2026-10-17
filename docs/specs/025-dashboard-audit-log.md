# SPEC-025: The Audit log page

- Status: Done
- ADRs: ADR-0028
- Roadmap phase: 5

## Problem

Every change to the rules, the exemptions and the emergency settings writes an audit entry, from the dashboard and the CLI alike (SPEC-019). Today only a rule's page shows its own last changes. To answer "who turned bypass on?", "what did Alice change last night?" or "what changed during the incident?", an admin needs the whole log, filtered. The dashboard links to an Audit log page that is still a placeholder.

## Behavior

### The page, at `{path}/audit`
For viewers and managers alike. Nothing on it changes anything.

- **The table,** from `GET api/audit`, newest first. Each entry shows:
  - when (in the browser's zone, UTC on hover);
  - who: the actor as written, such as `user:App\Models\User:42` or `cli:deploy`;
  - the action, in words, such as "Rule changed" or "Bypass";
  - the target: a rule's links to its page (`rule:12`), and others show as written (`exemption:5`, `setting:bypass`);
  - what changed, in one line: for a change, each field as `before → after`; for a creation, what was created; for a deletion, what was deleted.
- **Details** on each entry, on demand: the before and the after side by side, in full, and the request's IP and user agent.
- **Filters,** combined, kept in the page's URL so a filtered view can be shared:
  - action (any, or one of the nine);
  - target, exact, such as `rule:12`;
  - actor, exact, such as `cli:deploy`;
  - from and until, times in the browser's zone, sent to the API in UTC.
  - A rule's page already links to `/audit?target=rule:12`.
- **More on demand:** "Load more" fetches the next 100 with the API's cursor, below the ones shown. It is gone when there are no more.
- **Messages:** no entry yet; no entry matching the filters, with a way to clear them; and the API's error, such as a missing table or an `until` before `from`.

## Acceptance criteria

### Frontend (Vitest)
- **AC-1** The table lists each entry's time, actor, action in words, target, and what changed, in the order the API gives. A rule's target links to its page.
- **AC-2** Details shows the entry's before and after side by side, in full, with its IP and user agent.
- **AC-3** The action, target, actor, from and until filters go to the API and into the page's URL. A URL with filters, such as `/audit?target=rule:12`, opens filtered. "Clear filters" shows the whole log again.
- **AC-4** "Load more" asks for the next page with `next_cursor`, adds it below, and is gone when `next_cursor` is null.
- **AC-5** An empty log, an empty filtered log and an error from the API each show their message.
- **AC-6** A viewer sees the same page as a manager: it has no control that changes anything.

## Interfaces and files

- `resources/dashboard/pages/audit/`: `index.tsx`, and its tests.
- `resources/dashboard/lib/audit.ts`: the filters, the paged query, the actions' words and the change summary.
- `resources/dashboard/pages/index.tsx`: the route.

## Out of scope

- Free-text search, which SPEC-019 left for a user who needs it.
- Exporting the log, or deleting entries: the log is append-only (SPEC-019).
- Undoing a change from its entry.

## Edge cases and failure modes

- **A long log:** each page is the API's cursor page of 100, which costs the same at any depth, and the page never counts the table.
- **A rule deleted since:** its target still shows `rule:12`, linking to a page that says the rule is gone.
- **Large before or after,** such as a rule with a response override: the row's summary is cut to one line, and Details shows all of it, wrapped.
- **Times in another zone:** from and until are entered in the browser's zone and sent in UTC, as the table stores them.
- **The table is missing:** the API answers 503 with what to run, which the page shows.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test`, `composer analyse`
- In the Workbench app: change a rule and turn bypass on and off, then open the Audit log, filter by action and by target, and open an entry's details.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **Filters live in the URL,** so a rule's History links to its entries, and a filtered view can be shared during an incident.
2. **"Load more" with the cursor,** rather than numbered pages: the API pages by cursor so a page costs the same at any depth (SPEC-019 decision 2).
3. **One line per entry, the full before and after on demand,** so the table stays scannable and nothing is hidden.
4. **Times in the browser's zone, sent in UTC,** as everywhere in the dashboard.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-025-1` | This spec | Decisions |
| 2 | `phase-5/spec-025-2` | The page, its filters, details and "Load more", the guide, the changelog and the spec's close | AC-1 to AC-6 |

## Open questions

None.
