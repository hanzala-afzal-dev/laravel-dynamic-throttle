# SPEC-034: Dashboard links for apps without a login, and for incidents

- Status: In progress
- ADRs: ADR-0031 (new, amends ADR-0015), ADR-0007, ADR-0011, ADR-0017, ADR-0027
- Roadmap phase: after 0.1.0, in 0.2.0

## Problem

Outside the `local` environment, only the two gates let anyone into the dashboard (ADR-0015), and a gate checks a signed-in user. An app without a user login has nobody to let in, and an app with one cannot quickly show the dashboard to a helper during an incident. The maintainer asked for a way in on 2026-10-19 (the roadmap's "Dashboard access in production").

## Behavior

With `dashboard.links` on, `php artisan dynamic-throttle:link --name="alice, incident 42"` prints a link that opens the dashboard once, for 60 minutes. Alice opens it, presses "Continue", and works in the dashboard until the link ends. The audit log names who made the link, when Alice used it, and every change she made.

- **Making a link:**
  - `--name` says who it is for, and is required.
  - `--for` takes the format the other commands take (`30m`, `2h`, `1d`): 60 minutes by default, and a day at most.
  - A link only views, unless `--manage`, which in production asks to confirm, as the CLI's dangerous changes do (`--force` without a terminal).
  - The command prints the link's id, who it is for, what it allows, when it ends, and the link: `https://example.com/dynamic-throttle/link#<token>`. It warns when the link starts with `http://` outside `local`.
- **Using a link:**
  - The link's page, `{path}/link`, needs no gate. It takes the token from the address, removes it from the address bar at once, and shows a "Continue" button. It sends nothing before the button is pressed, so a chat app's link preview never uses the link.
  - "Continue" posts the token to `{path}/api/link`. A valid link is marked used and starts a session, then the page moves to `{path}`, the plain address.
  - A used, revoked, expired or unknown link gets one message: "This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one."
- **While the session lasts:**
  - The dashboard lets its holder view, or also change things with a `--manage` link, as the gates would.
  - Each request checks the link, so the session ends at the link's end time, or at the next request after `--revoke`.
  - The dashboard's header shows the link's name and when it ends.
  - Links add to the gates: a request gets what either allows.
- **Ending and listing links:**
  - `--revoke=<id>` ends one link, used or not. `--revoke-all` ends every link that has not ended.
  - `--list` shows the links that have not ended, without their tokens.
- **The audit log** has an entry for each link made (`link.create`, by `cli:<system user>`), used (`link.use`, with the IP and user agent) and ended (`link.revoke`). A change made through a link names it as the actor, `link:7:alice, incident 42`.
- **The `dashboard` health check** (SPEC-033) knows about links. Outside `local`, with no gate defined and links on, it is ok: only links open the dashboard. It warns when links are on but their table is missing, or the dashboard's middleware starts no session.

## Decisions

The maintainer vetoes any of these in this PR (ADR-0027). **(roadmap)** marks what the roadmap already asked for; **(recommended)** marks Claude's recommendation.

1. **Links live in a table of their own, `dynamic_throttle_links`,** not the cache (recommended, the roadmap's open question). A link must survive `cache:clear`, which someone may run during the very incident it is for, and a cache that evicts keys when full. It must be seen by every server, which the file and APCu stores do not do. Listing links and ending them all needs a query. The cost is one migration, which only an app that turns links on needs (ADR-0031).
2. **Off by default,** with `dashboard.links` (`DYNAMIC_THROTTLE_DASHBOARD_LINKS`) (roadmap). Off, the link routes do not exist, the command refuses, and a session's link is ignored. Nothing reads the table, so an app that never turns links on needs no migration.
3. **Only the CLI makes links** (recommended). A link's session cannot make another, so a leaked link cannot extend itself.
4. **`--for` is 60 minutes by default, and at most a day,** parsed as the other commands parse it (roadmap). A link views unless `--manage` (roadmap).
5. **The token is 32 random bytes in the link's fragment,** as 43 characters of base64url after `#` (recommended). Browsers never send a fragment to a server, so it never reaches a server log or a `Referer` header. The page removes it from the address bar and the browser's history with `history.replaceState` before anything else. Rejected: the token in the path, which every web server logs.
6. **The page waits for "Continue"** (roadmap), so a link preview, or a mail scanner that runs scripts, never uses the link.
7. **Only a SHA-256 hash of the token is stored** (roadmap). The token is 256 random bits, so a fast hash is enough, as for Sanctum's tokens. The command prints the token once; nothing can show it again.
8. **A link works once** (roadmap: "one-time"). Using it is one atomic update that sets `used_at` only while the link is unused, unrevoked and unexpired, so two tabs that press "Continue" at once cannot both use it. A holder who loses the session asks for a new link.
9. **The session holds the link's id only,** under `dynamic-throttle.link`, and the session's id is regenerated when the link is used. The link is read from the table on each of the dashboard's requests, which is not the request path (ADR-0007).
10. **Links add to the gates** (recommended): `view` is what a gate or the link allows, and `manage` too. In `local`, with no gate defined, everyone gets in as before.
11. **A change's actor is whoever allowed it** (recommended): the signed-in user when a gate allows the change, otherwise the link. A link's actor is `link:<id>:<name>`, such as `link:7:alice, incident 42`. The roadmap's example was `link:alice, incident 42`. The id is added because names are not unique, the same way a user's actor ends with their id.
12. **Three audit actions,** written by `EmergencyControls`, which stays the log's only writer (SPEC-013 AC-23) (recommended):
    - `link.create`: actor `cli:<system user>`, target `link:<id>`, after the name, what it allows, its end and its creator.
    - `link.use`: actor `link:<id>:<name>`, target `link:<id>`, with the IP and user agent.
    - `link.revoke`: actor `cli:<system user>`, target `link:<id>`. `--revoke-all` writes one entry per link it ends, in one transaction, so each link's history is under its own target.
    - No entry ever holds the token or its hash.
13. **`--manage` asks to confirm in production,** as the CLI's other dangerous changes do (ADR-0015 point 4); a view link does not (recommended).
14. **`--list`, `--revoke` and `--revoke-all` are options of the same command** (roadmap for the last two, recommended for `--list`, so the ids `--revoke` needs can be found).
15. **One message for every link that cannot be used,** with a 403, whether it was used, revoked, expired or never existed (recommended). A malformed token, or none, gets a 422.
16. **The `dashboard` health check, not a new one,** reports on links (recommended), since they decide the same thing: who can open the dashboard.

### Exact messages

- Links off: `Links are off: set DYNAMIC_THROTTLE_DASHBOARD_LINKS=true (dynamic-throttle.dashboard.links) to use them.`
- Dashboard off: `The dashboard is off (dynamic-throttle.dashboard.enabled), so a link would open nothing.`
- No name: `--name is required: who the link is for, such as "alice, incident 42".`
- Too long: `--for can be at most 1d for a link, got "2d".`
- More than one mode: `Pass one of --name, --list, --revoke or --revoke-all.`
- Made, view only:
  ```
  Link 7 for "alice, incident 42": view only, until 2026-10-30 11:30:00 UTC.
  https://example.com/dynamic-throttle/link#<token>
  It works once, in one browser. Send it privately: until it is used, anyone who has it can open the dashboard.
  End it early with: php artisan dynamic-throttle:link --revoke=7
  ```
  With `--manage`, "view only" reads "can view and change rules, exemptions and settings".
- Over `http://`: `The link starts with http://, so anyone on the network can read it: set APP_URL to an https:// address.`
- Confirming `--manage`: `The link lets whoever holds it change rules, exemptions and settings until 2026-10-30 11:30:00 UTC`
- Revoked: `Link 7 for "alice, incident 42" has ended.` Already ended: `Link 7 has already ended.` Unknown: `No link has the id 99.`
- Revoked all: `Ended 3 links.` None: `No link is active.`
- A link that cannot be used: `This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one.`
- Health, links only: `Only links from dynamic-throttle:link open the dashboard: the app defines neither viewDynamicThrottle nor manageDynamicThrottle.`
- Health, with a gate: the gate's message of SPEC-033, then `Links from dynamic-throttle:link open it too.`
- Health, no table: `Links are on, but ` then `TableMissing`'s message for `dynamic_throttle_links`.
- Health, no session: `Links are on, but the dashboard's middleware starts no session, so a link cannot sign anyone in: add the web group to dynamic-throttle.dashboard.middleware.`

## Acceptance criteria

### Making, listing and ending links (`dynamic-throttle:link`)

- **AC-1** Given links on, `--name="alice, incident 42"` makes a link that only views, for 60 minutes, and exits 0. It prints the "Made" message of the exact messages, with a link of `APP_URL`, the dashboard's path (on `dashboard.domain` when set), `/link#` and 43 base64url characters. The table holds the token's SHA-256 hash, not the token, and `created_by` is `cli:<system user>`. One `link.create` entry is written, which holds neither the token nor its hash.
- **AC-2** `--for` accepts `90s`, `30m`, `2h` and `1d` (86,400 seconds), and the link ends that long after it is made. `25h` and `2d` are refused with the "at most 1d" message, and `0m` and `soon` with the other commands' message. Each refusal exits 1 and writes nothing.
- **AC-3** `--manage` makes a link that can manage, and says so. In `production`, without a terminal and without `--force`, it is refused, exits 1 and writes nothing; with `--force`, it is made. In `local`, it asks nothing.
- **AC-4** A missing or empty `--name`, one of 101 characters, and one with a control character or an invisible format character, such as a right-to-left override, are refused, exit 1 and write nothing. A name of 100 characters is accepted.
- **AC-5** `APP_URL=http://example.com` in `production` makes the link and prints the `http://` warning. `https://` or `local` prints none.
- **AC-6** `--list` shows the links that have not ended, newest first: id, name, view or manage, used or not, end time and creator. Expired and revoked links are left out. With none, it prints "No link is active." It never prints a token or a hash.
- **AC-7** `--revoke=7` ends link 7, used or not, writes one `link.revoke` entry and exits 0. Again, it prints "Link 7 has already ended.", exits 0 and writes nothing. `--revoke=99` prints "No link has the id 99." and exits 1, and `--revoke=abc` is refused.
- **AC-8** `--revoke-all` ends every link that has not ended, with one `link.revoke` entry each, in one transaction, and prints how many. With none, it prints "No link is active.", exits 0 and writes nothing.
- **AC-9** With links off, the default, each mode is refused with the "Links are off" message and exits 1, without reading the table, so it works with no table at all. With the dashboard off, each mode is refused with its message. With links on and no table, each mode is refused with `TableMissing`'s message.
- **AC-10** Two modes at once, such as `--revoke=7 --name=x` or `--list --revoke-all`, are refused with the "Pass one of" message.

### Using a link

- **AC-11** With links on, in `production`, with no gate defined, a guest's `GET {path}/link` gets 200 and the dashboard's page, with its Content Security Policy and headers. It reads no link and changes nothing. With links off, `GET {path}/link` and `POST {path}/api/link` get 404.
- **AC-12** `POST {path}/api/link` with a valid token gets 204. The link's `used_at` is set, the session's id changes, and the session holds the link's id. Then `GET {path}/api/me` gets 200 with `can` view only and `link` with its name, `manage` false and its end time. A read route gets 200, and a write route 403. One `link.use` entry is written, with actor `link:7:alice, incident 42`, target `link:7`, and the request's IP and user agent.
- **AC-13** The same token again, from this session or another, gets 403 with the "cannot be used" message and changes nothing. So do a revoked link, an expired one and a token no link has. A missing token, or one that is not 43 base64url characters, gets 422.
- **AC-14** Two requests that use the same unused token at once: exactly one gets 204, and one `link.use` entry is written.
- **AC-15** A `--manage` link's session can change what a manager can, with `confirm` where SPEC-018 asks for it. The change's audit entry has actor `link:7:alice, incident 42`, with the request's IP and user agent.
- **AC-16** The session ends with its link. Once the link's end time passes, its next request gets 403. After `--revoke`, the next request gets 403. With links turned off, the session's link is ignored, so in `production` with no gate, it gets 403.
- **AC-17** Links and gates add up. A signed-in viewer holding a `--manage` link can manage, and a change's actor is the link. A signed-in manager holding a view link manages as before, and a change's actor is the user. In `local` with no gate, everyone gets in, as before.
- **AC-18** `POST {path}/api/link` without a CSRF token gets 419, as any `web` route does.
- **AC-19** Neither the token nor its hash is in the session, in an audit entry, or in any URL the server receives.
- **AC-20** The dashboard's own limit applies: the 121st `POST {path}/api/link` in a minute from one IP gets 429. A `*` rule of 1 request a minute never limits `{path}/link` or `{path}/api/link` (SPEC-018 AC-9).

### The link's page and the header

- **AC-21** At `{path}/link#<token>`, the page removes the fragment from the address bar before anything else, so the history entry has no token, and shows "Continue". It makes no request to `api/link` until "Continue" is pressed.
- **AC-22** "Continue" posts the token, and on 204 replaces the address with `{path}`. On 403 or 422, it shows the server's message. With no token in the address, such as after a reload, it says: "This address has no link in it. Open the link you were sent again."
- **AC-23** In a link's session, the header shows "Link for alice, incident 42, until 11:30", in the browser's time zone, as the dashboard's other times are.

### Health and the hard rules

- **AC-24** With links on, outside `local`, and no gate defined, the `dashboard` check is ok with the "links only" message, so `--strict` does not fail on it. With a gate defined, it adds "Links from dynamic-throttle:link open it too." With links off, it says what SPEC-033 says.
- **AC-25** With links on, the `dashboard` check warns when the `dynamic_throttle_links` table is missing, and when the dashboard's middleware starts no session, with the exact messages.
- **AC-26** The arch tests hold: the links' code is in `Dashboard`, `Console`, `Models` and `Support`, never used by `Http` or `Engine`, and `EmergencyControls` stays the audit log's only writer.

## Interfaces and files

- `config/dynamic-throttle.php`: `dashboard.links`, from `DYNAMIC_THROTTLE_DASHBOARD_LINKS`, false by default.
- `database/migrations/<date>_create_dynamic_throttle_links_table.php`, a new migration:
  - `id`
  - `name`, 100 characters
  - `token_hash`, 64 characters, unique
  - `can_manage`, a boolean
  - `created_by`, 255 characters
  - `expires_at`, a datetime, indexed
  - `used_at` and `revoked_at`, nullable datetimes
  - `created_at`, a datetime
  - Datetimes, not timestamps, as the audit log's, so they reach past 2038.
- `src/Models/DashboardLink.php`: the model, on the package's connection, with times in UTC.
- `src/Dashboard/Links/`, `@internal`:
  - `LinkToken.php`: makes a token and hashes one.
  - `SessionLink.php`: the session's link, if it is still valid, and what it allows.
- `src/Console/LinkCommand.php`: `dynamic-throttle:link {--name=} {--for=60m} {--manage} {--list} {--revoke=} {--revoke-all} {--force}`, on `ChangeCommand` for the confirmation.
- `src/Support/EmergencyControls.php`: `createLink()`, `useLink()`, `revokeLink()` and `revokeLinks()`, each with its audit entries in one transaction.
- `src/Enums/AuditAction.php`: `link.create`, `link.use` and `link.revoke`.
- `src/Dashboard/Gates.php`: `abilities()` adds the session's link.
- `src/Dashboard/LinkController.php`: the page and the use.
- `src/Dashboard/DashboardRoutes.php`: `GET {path}/link` (`dynamic-throttle.link`), the page without a gate, and `POST {path}/api/link` (`dynamic-throttle.api.link`), with the headers and the limit but no gate. Only with links on.
- `src/Dashboard/MeController.php`: `link` in `api/me`, or null.
- `src/Support/Changes/Actor.php`: the link as the actor when no gate allowed the change.
- `src/Support/Health/Checks/DashboardCheck.php`: the link cases.
- `resources/dashboard/`: the link's page, outside the dashboard's frame, and the header's line. `dist/` is built after merge (ADR-0030).
- Docs:
  - `docs/guide/dashboard.md`: a "Links" section under "Who gets in".
  - `docs/guide/incidents.md`: giving a helper the dashboard for an hour.
  - `docs/guide/health.md`: the new messages.
  - `docs/guide/uninstalling.md`: the new table.
  - `README.md`: the install step's line on apps without a login.
  - `CHANGELOG.md`: under Added, with the migration step.

Public API (ADR-0017): a new config key, a new command, a new migration, and new audit actions. Nothing changes for an app that leaves links off.

## Out of scope

- Making, listing or ending links from the dashboard (decision 3).
- A button that ends a link's session early. `--revoke` does it.
- Tying a session to its IP, which would end it on every network change of a phone.
- Deleting ended links from the table. They are a few rows per incident, and the audit log keeps their history anyway. `dynamic-throttle:prune` could do it later.
- Tokens for scripts that call the JSON API.
- Finer permissions than view and manage (the roadmap's "Considered, not planned").

## Edge cases and failure modes

- **The link's address comes from `APP_URL`,** or `dashboard.domain`, since a command knows no request. A wrong `APP_URL` makes a wrong link: the command prints it, so it shows.
- **The app's sessions end sooner than the link,** such as after `session.lifetime` minutes idle. The session ends first, and the holder needs a new link, as the docs say.
- **`cache:clear` with sessions in the cache store** ends every session, a link's too. The link itself stays, already used.
- **`dashboard.path` changes after a link is made:** the link's address gets 404. Make a new one.
- **Links turned off, then on again:** a link that has not ended works again, since only its own end time and `--revoke` end it.
- **A mail scanner that presses buttons** uses the link before its holder does. The holder sees the message, and the audit log's `link.use` entry shows the scanner's IP. Revoke it and make a new one.
- **A link that leaks before it is used:** anyone can use it once. `--revoke` ends it; `--list` shows whether it was used.
- **Clocks:** `expires_at` is in UTC, and the server's clock decides, as for every end time the CLI sets.
- **Several servers** share the table, so a link made on one works on all.
- **The database is down:** using a link fails with the app's 500, and a link's session gets 403s until it is back, as the gates' callbacks that read a database would.

## End-to-end verification

- `composer test -- --filter=Link`, then `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`.
- `npm run test`, `npm run typecheck`, `npm run lint`.
- In the Workbench app, with `APP_ENV=production`, no gate and `DYNAMIC_THROTTLE_DASHBOARD_LINKS=true`:
  1. `php artisan dynamic-throttle:link --name=test`, then open the link in a browser: the address bar shows no token, "Continue" opens the dashboard at `/dynamic-throttle`, and the header names the link.
  2. Open the same link in a private window: it says the link is not valid.
  3. `php artisan dynamic-throttle:link --revoke=<id>`: the dashboard's next request gets 403.
  4. `php artisan dynamic-throttle:health`: the `dashboard` check says only links open it.

## PRs

Stacked on each other, each green on its own, every one against `develop` (ADR-0027, ADR-0032). This first one is the spec and ADR-0031 alone, since ADR-0031 amends ADR-0015: no code until it is reviewed. The review subagent reads PRs 2 and 3 before they open; its findings become acceptance criteria here, fixed test-first.

| PR | Branch | Adds | Covers | `src` lines, about |
|---|---|---|---|---|
| 1 | `beta/spec-034-1` | This spec, ADR-0031, ADR-0015's note, the ADR index and the roadmap's line | Decisions | 0 |
| 2 | `beta/spec-034-2` | The config key, the migration, the model, the token, the audit actions, `EmergencyControls`' link changes and `dynamic-throttle:link` | AC-1 to AC-10 | 450 |
| 3 | `beta/spec-034-3` | The two routes, using a link, the session's link in the gates, `api/me` and the actor | AC-11 to AC-20 | 350 |
| 4 | `beta/spec-034-4` | The link's page and the header's line, in the dashboard's frontend | AC-21 to AC-23 | 250 |
| 5 | `beta/spec-034-5` | The health check, the arch tests, the docs, the changelog, the review's fixes, and the spec's close | AC-24 to AC-26 | 100 |

## Open questions

None: each question has a recommended decision above, which the maintainer vetoes in this PR.
