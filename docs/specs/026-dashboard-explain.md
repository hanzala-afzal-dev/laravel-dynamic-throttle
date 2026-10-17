# SPEC-026: The Explain page

- Status: Done
- ADRs: ADR-0008, ADR-0028
- Roadmap phase: 5

## Problem

"Why was this request blocked?" and "would this request be blocked?" are the questions an admin asks most. `dynamic-throttle:explain` answers them from a shell, and `POST api/explain` answers them as JSON (SPEC-014, SPEC-017). The dashboard links to an Explain page that is still a placeholder, so whoever has no shell on the servers cannot ask.

## Behavior

### The page, at `{path}/explain`
For viewers and managers alike, as the API's `inspect` gate allows. It never counts the request it explains, and changes nothing.

- **The form:** the method (GET by default), the path, and optionally the client IP (127.0.0.1 when empty), the user's id, the guard that looks the user up, and headers, one `Name: value` a line. "Explain" sends them in the body of `POST api/explain`, never in the URL, since a header can hold a token (decision 1).
- **The decision** the next such request gets, in words:
  - allowed, with its rate-limit headers;
  - blocked with a 429 by a rule, named and linked, with its headers;
  - a 503 from a fail-closed rule that could not be checked, or through anyway past a fail-open one;
  - unknown, when the counters could not be read;
  - untouched, with why: the route does not run `dynamic.throttle`, the package is off, it is the dashboard's own route, `except` lists the path, bypass is on, or no rule matches.
- **The rules** the request matches, in the order they run: the name (a link), the method and pattern, the mode, the limit under the scale factor and the window, the scope, and what the rule does with the request, in words: counts it, exempt, skipped, ignored over the cap, failed, or not reached. For a rule that counts it: the identity, as the API masks it, and the counter: the count, what remains, and whether the next request goes through, or its `Retry-After`. An exemption or an error shows in its row.
- **The context:** the route, its middleware in running order, whether `dynamic.throttle` runs, and whether it runs after authentication; the manifest's version, rules and exemptions; bypass and the scale factor when on; and whether the counters were read.
- **Errors:** a field the API refuses shows its message under the field. An explanation that cannot be made, such as for a user the guard cannot find, shows its message above the result.

## Acceptance criteria

### Frontend (Vitest)
- **AC-1** "Explain" sends the method, path, IP, user, guard and headers, one a line, in the body of `POST api/explain`, and empty optional fields as null. The page's URL keeps none of them.
- **AC-2** The decision shows its outcome in words: allowed, blocked by a named rule with a link, 503, through past a failure, unknown, or untouched with its reason in words. Its rate-limit headers show as a table.
- **AC-3** The rules table lists each matched rule in order, with its limit and window, scope, status in words, identity and counter. An exemption or an error shows in its row. With no rule matched, the page says so.
- **AC-4** The route, its middleware in order, whether `dynamic.throttle` runs and after authentication, the manifest, bypass and scale when on, and whether the counters were read, all show. Without a route, the page says no route matches.
- **AC-5** A 422 shows each field's message under its field, and a message for the request as a whole above the result.
- **AC-6** A viewer can explain a request; the page has no control that changes anything.

## Interfaces and files

- `resources/dashboard/pages/explain/`: `index.tsx`, its parts, and their tests.
- `resources/dashboard/lib/explain.ts`: the explanation's type, the request, and the words for outcomes, reasons and statuses.
- `resources/dashboard/pages/index.tsx`: the route.

## Out of scope

- Sending the real request, or counting it: explain only reads (SPEC-014).
- Remembering past explanations, or sharing one by link, which would put headers in a URL.
- Choosing a route by name: the path is what the middleware matches.

## Edge cases and failure modes

- **A header holds a token:** it goes in the body only; the API masks it in the identity it answers (SPEC-017), and the page shows it as answered.
- **A user that does not exist,** or a guard that is not one: the API answers 422 for the request, which the page shows.
- **The counters are down:** the explanation answers with the counters unread and its error, and the decision says unknown when it depends on them.
- **A long middleware list or pattern:** it wraps.

## End-to-end verification

- `npm run test`, `npm run typecheck`, `npm run lint`, `npm run build`, `npm run budget`
- `composer test`, `composer analyse`
- In the Workbench app: explain `GET /api/orders` from an IP the demo traffic blocked, and from a fresh one; then a path no rule matches.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The form's values stay out of the URL,** unlike the other pages' filters, since a header can carry a token and URLs end up in history and logs.
2. **One request at a time:** the page shows the last explanation, and "Explain" again replaces it.
3. **Words, not codes:** outcomes, reasons and statuses read as sentences, with the API's values one hover away.

## PRs

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-5/spec-026-1` | This spec | Decisions |
| 2 | `phase-5/spec-026-2` | The page, the guide, the changelog and the spec's close | AC-1 to AC-6 |

## Open questions

None.
