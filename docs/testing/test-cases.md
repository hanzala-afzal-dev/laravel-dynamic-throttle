# Test cases

Every behavior a user can see, as a test case: what to do, and what must happen. A result that differs from **Expected** is a bug, in the code or in the docs.

Each case has an id, such as `RUL-07`. The **Covered by** column says where it is checked:

- **E2E:** the browser suite in `e2e/`, which runs the dashboard in Chromium against the Workbench app (SPEC-032). Its tests start with the case's id: `test('RUL-07: …')`.
- **Pest** or **Vitest:** the PHP suite in `tests/`, or the dashboard's component tests next to its code. The spec named holds the acceptance criterion.
- **CI:** a job in `.github/workflows/ci.yml`, such as the install into a fresh Laravel app.
- **Manual:** by hand, or by an agent, in a real app. Most of these are also covered by Pest; the manual run checks them in your app's setup.

## Before a manual run

- **Wait 3 seconds after a rule or setting changes.** Servers pick up a change within `manifest.refresh_seconds`, 2 by default.
- **Read the headers** with `curl -s -D - -o /dev/null <url> | grep -iE '^HTTP|^x-ratelimit|^retry-after'`.
- **A POST in the `web` group needs a CSRF token.** Without one, Laravel answers 419 before the package sees the request.
- **When a result surprises you,** run `php artisan dynamic-throttle:explain <METHOD> <path>` before you report it: it says which rules matched and why.

## Install and setup

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| INS-01 | In a fresh Laravel 12.69+ or 13.30+ app: `composer require hanzala-afzal/laravel-dynamic-throttle:^0.1@beta` | It installs and is discovered. `php artisan about` has a **Dynamic Throttle** section: Enabled, and the version | CI (install), Manual |
| INS-02 | `php artisan vendor:publish --tag=dynamic-throttle-migrations`, then `php artisan migrate` | Five tables: `dynamic_throttle_rules`, `_exemptions`, `_settings`, `_audit_log` and `_metrics_hourly` | CI (install) |
| INS-03 | `php artisan dynamic-throttle:compile` | `Compiled manifest version …: 0 rules, 0 skipped; 0 exemptions, 0 skipped.` | CI (install) |
| INS-04 | `php artisan dynamic-throttle:health`, after the README's steps | No error, and exit code 0. Warnings may show, such as `store` on the database store, `attached` before the middleware is attached, and `metrics` before the scheduler ran | CI (install) |
| INS-05 | `php artisan schedule:list` | `dynamic-throttle:compile` every five minutes, `dynamic-throttle:flush-metrics` every minute, `dynamic-throttle:prune` daily | CI (install), Manual |
| INS-06 | A rule, with the middleware attached to no route | No request is limited and none gets `X-RateLimit-*` headers. Health warns `attached` | Pest (SPEC-016) |
| INS-07 | Open `/dynamic-throttle` in the `local` environment, with no gate defined | The dashboard opens, for a guest too | Pest (SPEC-018), E2E |

## Limiting requests

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| LIM-01 | An enforcing rule, 2 per minute per IP, on a route. Send 3 requests | 200 twice, with `X-RateLimit-Limit: 2` and `X-RateLimit-Remaining` 1, then 0. The 3rd gets 429 with `Retry-After` (at most 60), `X-RateLimit-Reset` (a Unix time), `X-RateLimit-Limit: 2` and `X-RateLimit-Remaining: 0` | E2E, Pest (SPEC-010) |
| LIM-02 | The 429's body, with no `response_override` | Laravel's own 429: the "Too Many Requests" page, or `{"message":"Too Many Attempts."}` for a JSON request | Pest (SPEC-010) |
| LIM-03 | A rule whose `response_override` is `{"message":"Slow down"}` | The 429's body is that JSON, with the same headers, even for an HTML page. By design, as Laravel's `Limit::response()` | E2E, Pest (SPEC-010) |
| LIM-04 | After a 429, wait `Retry-After` seconds | The next request goes through. Counts fade over up to two windows, as in any sliding window | Pest (SPEC-001) |
| LIM-05 | A dry-run rule, over its limit | Every request answers 200, with no `X-RateLimit-*` header from it. Each would-block dispatches `RateLimitWouldBlock` after the response, and shows in the dashboard | E2E, Pest (SPEC-011) |
| LIM-06 | Two enforcing rules match, limits 10 and 3 | Allowed responses carry the 3's headers, the most restrictive. The 4th request is refused by the stricter rule, and both rules counted it | Pest (SPEC-007, SPEC-010) |
| LIM-07 | More than 5 enforcing rules match one request | The first 5, by priority then id, count it. Health's `ignored` check reports the rest | Pest (SPEC-007, SPEC-016) |
| LIM-08 | Save or change a rule, then send requests | Every server applies the change within `manifest.refresh_seconds` | E2E, Pest (SPEC-006) |
| LIM-09 | A `*` rule; request `/up` | Never limited: `except` lists it, with Horizon, Telescope, Pulse, Nova and Livewire's paths | Pest (SPEC-018) |
| LIM-10 | A `*` rule of 1 request a minute; open the dashboard | The dashboard never gets that rule's 429; only Laravel's own limit of 120 a minute applies to it | E2E, Pest (SPEC-018) |
| LIM-11 | `DYNAMIC_THROTTLE_ENABLED=false`, then `php artisan config:clear` | No request is limited and none gets `X-RateLimit-*` headers | Pest (SPEC-010) |

## What a rule matches

One enforcing rule, `GET` on `posts`, 2 a minute per IP. Check whether each request is counted, with `explain` or by its headers.

| ID | Request | Expected | Covered by |
|---|---|---|---|
| MAT-01 | `GET /posts?page=2` | Counted: the query string is ignored | Pest (SPEC-007) |
| MAT-02 | `GET /posts/` | Counted: slashes around the path are ignored | Pest (SPEC-007) |
| MAT-03 | `GET /p%6Fsts` | Counted: the path is URL-decoded, as the router decodes it | Pest (SPEC-007) |
| MAT-04 | `HEAD /posts` | Counted: a `GET` rule also covers `HEAD` | Pest (SPEC-007) |
| MAT-05 | `GET /Posts` | Not counted: matching is case-sensitive | Pest (SPEC-007) |
| MAT-06 | `GET /posts/1` | Not counted: `posts` matches `posts` only. `posts/*` matches `posts/1` and `posts/1/comments`, but not `posts` | Pest (SPEC-007) |
| MAT-07 | `POST /posts` with `_method=DELETE` | Counted by a `DELETE` rule, not a `POST` one, as Laravel routes it | Pest (SPEC-007) |
| MAT-08 | A pattern `/posts`, with a leading slash | Works as `posts`. The pattern `/` matches the root only | Pest (SPEC-007) |

## Who a rule counts

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| IDN-01 | Scope `ip`, from two IPs | Each IP has its own count. IPv6 addresses in the same /64 share one (`scopes.ip.ipv6_prefix`) | Pest (SPEC-008) |
| IDN-02 | Scope `user`, two signed-in users | Each user has their own count | Pest (SPEC-008) |
| IDN-03 | Scope `user`, a guest, `on_missing_identity` `ip` | The guest is counted by IP, apart from any user | Pest (SPEC-008) |
| IDN-04 | The same with `on_missing_identity` `skip` | The rule ignores guests | Pest (SPEC-008) |
| IDN-05 | Scope `api_token`, with and without a bearer token | One count per token. Without one, the request has no identity, and `on_missing_identity` decides | Pest (SPEC-008) |
| IDN-06 | Scope `header` `X-Tenant`, two values, then none | One count per value. Without the header, `on_missing_identity` decides | Pest (SPEC-008) |
| IDN-07 | Scope `global` | One count for everyone | Pest (SPEC-008) |
| IDN-08 | Scope `custom`, a resolver that returns `null` | No identity: `on_missing_identity` decides | Pest (SPEC-008) |
| IDN-09 | A custom resolver that throws | Fail-open rule: the request goes through, and the error is reported once per cooldown. Fail-closed rule: 503. In strict mode, the error is thrown | Pest (SPEC-008, SPEC-012) |

## Exemptions, bypass and scaling

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| EMG-01 | `Exemption::forIp('127.0.0.1')->because('test')->save()` | That IP is never limited, by any rule. A CIDR range, such as `127.0.0.0/8`, works the same | E2E, Pest (SPEC-009) |
| EMG-02 | An exemption with `->forRule($rule)` | Only that rule skips the client; the others still count it | E2E, Pest (SPEC-009) |
| EMG-03 | An exemption with `->until(now()->addMinute())` | It stops after a minute | Pest (SPEC-009) |
| EMG-04 | `php artisan dynamic-throttle:bypass on --for=30m` | Every request goes through. `dynamic-throttle:status` shows bypass on, until when | E2E, Pest (SPEC-013) |
| EMG-05 | `bypass on` with no `--for` | It ends after one hour. With `--forever`, it has no end, and `health --strict` exits 1 (`emergency`) | Pest (SPEC-013, SPEC-029) |
| EMG-06 | `php artisan dynamic-throttle:scale 2 --for=2h` | Every limit doubles: `X-RateLimit-Limit: 4` for a limit of 2 | E2E, Pest (SPEC-013) |
| EMG-07 | `scale 0.5` on a limit of 1 | A limit is `max(1, ceil(limit × factor))`: it stays 1 | Pest (SPEC-013) |
| EMG-08 | `scale 11`, `scale 0.05`, `scale 1.234` | Refused, with why: from 0.1 to 10, with at most two decimals | Pest (SPEC-013) |
| EMG-09 | In production: `bypass on`, a factor below 1, or `disable-rule` on an enforcing rule | Each asks to confirm; `--force` skips the question | Pest (SPEC-013) |
| EMG-10 | Any change from the CLI | An audit entry, with `cli:` and the system user as the actor | E2E, Pest (SPEC-013) |

## When something fails

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| FAI-01 | Stop Redis while rules apply | Fail-open rules let every request through. The error is reported once per cooldown (10 seconds), not per request, and `StorageUnavailable` is dispatched. Health shows the store's error | Pest (SPEC-012, SPEC-029), Manual |
| FAI-02 | The same, with a fail-closed rule | Its routes answer 503 with `Retry-After`. A dry-run rule never answers 503 | Pest (SPEC-012) |
| FAI-03 | `php artisan cache:clear` | Counters start from zero. The next limited request rebuilds the manifest, after its response. Until then, health reports it missing | Pest (SPEC-006, SPEC-029) |
| FAI-04 | `php artisan config:cache` and `route:cache` | Limiting and the dashboard work the same | Pest (SPEC-018) |
| FAI-05 | The database is down | Limiting goes on, since no request reads the database. The dashboard's pages show the API's errors | Pest (arch tests), Manual |
| FAI-06 | A change is saved, but its compile fails | The dashboard says "Saved, but servers keep the previous manifest until a compile succeeds: …" | Vitest (SPEC-021) |

## Artisan commands

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| CLI-01 | `dynamic-throttle:list` | Every rule with its id, name, method, pattern, scope, limit, mode, failure policy and whether it is enabled | Pest (SPEC-013) |
| CLI-02 | `dynamic-throttle:status` | Whether the package is enabled, the driver, the manifest's version and counts, bypass and scale. Exit 1 for an invalid config, such as an unknown driver | Pest (SPEC-013) |
| CLI-03 | `dynamic-throttle:explain GET /posts --user=1` | The decision, each matched rule and its counter, the route and its middleware. It counts nothing. `--json` gives the same as JSON | Pest (SPEC-014) |
| CLI-04 | `dynamic-throttle:health`, with `--json`, with `--strict` | One line per check: ok, warning, error or skipped. Exit 1 on an error, and with `--strict` on a warning too | Pest (SPEC-016) |
| CLI-05 | `dynamic-throttle:disable-rule 12`, then `enable-rule 12` | The rule stops limiting, then limits again | Pest (SPEC-013) |
| CLI-06 | `dynamic-throttle:flush-metrics`, `dynamic-throttle:prune --hours=24` | The minutes go to the hourly table; older hours are deleted | Pest (SPEC-015) |

## Dashboard: the frame

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| SHL-01 | Open each page: Overview, Rules, Exemptions, Activity, Explain, Audit log, Settings | Each shows its heading. No console error, page error or Content Security Policy violation | E2E |
| SHL-02 | The sidebar | Seven links, in that order. The current page's link is marked (`aria-current="page"`), also on its sub-pages: `/rules/1` marks Rules | E2E |
| SHL-03 | Open `/dynamic-throttle/nope` | "Page not found", with a link back to the overview | E2E |
| SHL-04 | The header | The environment, such as `local`, and the role: Manager or Viewer | E2E |
| SHL-05 | Click the theme button three times | Its label goes "Theme: light", "Theme: dark", "Theme: system". Dark puts the `dark` class on `<html>`. After a reload, the choice stays | E2E |
| SHL-06 | Reload a deep link, such as `/dynamic-throttle/rules/1/edit` | That page opens directly | E2E |
| SHL-07 | The page's response headers | `Content-Security-Policy` with a nonce, the same as the page's `csp-nonce` meta tag and new on every load. `X-Frame-Options: DENY`, `Cache-Control: no-store`, `X-Content-Type-Options: nosniff`, `Referrer-Policy: same-origin` | E2E, Pest (SPEC-020) |
| SHL-08 | A file under `/dynamic-throttle/assets/` | `Cache-Control: public, max-age=31536000, immutable`. A file the build does not list: 404 | Pest (SPEC-020) |
| SHL-09 | Open the dashboard with JavaScript off | It says the dashboard needs JavaScript | E2E |
| SHL-10 | Turn bypass on from the CLI while a page is open | The page shows the banner within about 5 seconds, without a reload | E2E |
| SHL-11 | Hide the tab | The page stops asking the server until it shows again | Vitest (SPEC-020) |
| SHL-12 | An API call answered by a login page, after the session expired | "The server answered with another page, such as a login: reload the page." The frame stays | Vitest (SPEC-020) |
| SHL-13 | A page that fails to render | "This page failed: …" in the frame. Opening another page clears it | Vitest (SPEC-020) |
| SHL-14 | Times on any page | In the browser's time zone, with the UTC time as their title | Vitest (SPEC-020) |

## Dashboard: Overview

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| OVW-01 | The Health card | "Healthy", "Warnings" or "Errors", and up to 3 problems, errors first. "See every check" opens Settings | E2E, Vitest (SPEC-021) |
| OVW-02 | Click "Check again" | The health report is asked again | E2E |
| OVW-03 | The Rules card, then create an enforcing rule | It shows the enabled rules, with enforcing and dry-run, and the disabled ones. Enabled and enforcing go up by one | E2E |
| OVW-04 | A rule blocks requests | "Blocks in the last hour" grows by as many, within about 5 seconds, with its share of the checks | E2E |
| OVW-05 | The Traffic card's tabs: Hour, Day, Week | The chart for that period ("Blocks and would-blocks in the last day"). The Top rules card follows it: "By blocks, then would-blocks, in the last day" | E2E |
| OVW-06 | Top rules, after a rule blocked | The rule is listed, as a link to its page | E2E |
| OVW-07 | "Turn on bypass", for 15 minutes | A dialog, "Confirm the change", says what bypass does, and that the end time counts from when you confirm. Cancel changes nothing | E2E |
| OVW-08 | Confirm it | "Bypass is on until …". The banner "An emergency setting is on" shows on every page. Requests over a limit go through | E2E |
| OVW-09 | "Turn off bypass", in the banner | No dialog. Bypass is off, the banner goes, and limits apply again | E2E |
| OVW-10 | Bypass with "No end time" | "Bypass is on, with no end time." | E2E |
| OVW-11 | Factor 2, for 1 hour, then Apply | No dialog. "Every limit is multiplied by 2 until …", and the banner. `X-RateLimit-Limit` doubles | E2E |
| OVW-12 | Factor 0.5 | A dialog asks to confirm, since it lowers every limit | E2E |
| OVW-13 | Factor 11, 0.05, 1.234 or `abc` | A message under Factor. Nothing changes | E2E |
| OVW-14 | No factor typed | Apply is disabled | E2E |
| OVW-15 | A change while the server is unreachable | "The server could not be reached: check the connection, then try again." | E2E, Vitest (SPEC-021) |
| OVW-16 | The metrics API answers 503 | Its message shows in the Traffic and Top rules cards. Health and Rules still show | Vitest (SPEC-021) |

## Dashboard: Rules

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| RUL-01 | The list | Name (a link), Applies to (method and pattern), Per, Limit ("5 per minute"), Mode, Status, Last hour. By priority, then id | E2E |
| RUL-02 | Type in "Search rules" | After a short pause, the URL gets `?search=…`, and only the rules whose name or pattern holds it show, ignoring case. Spaces around it are ignored | E2E |
| RUL-03 | A search that matches nothing | "No rule matches the search and filters." | E2E |
| RUL-04 | Choose Status and Mode, then reload | The list narrows; the URL holds them; after the reload they still apply | E2E |
| RUL-05 | From a searched list, click Rules in the sidebar | The search box empties, and every rule shows | E2E |
| RUL-06 | New rule | "A new rule starts in dry-run: …". Mode is Dry-run | E2E |
| RUL-07 | Template "Login" | Name Login, Method POST, Pattern `login`, Per IP, Attempts 5, Window 1 minutes, Mode Dry-run, Priority 10, 429 body `{"message":"Too many login attempts. Try again in a minute."}` | E2E |
| RUL-08 | Fill a rule in, then "Create rule" | The rule's page opens, with its settings. The list shows it | E2E |
| RUL-09 | Invalid input, one at a time: a 101-character name; pattern `api/`; a pattern with a space; Attempts 0; Window 2 days; Per Header with no name | Each is refused, with its message under its field, which is marked invalid. Nothing is saved | E2E, Pest (SPEC-018) |
| RUL-10 | 429 body `not json`, or `[1, 2]` | "The 429 body must be a JSON object, such as {"message": "Too many requests."}." Nothing is sent | E2E |
| RUL-11 | Window 1.5 minutes | Saved as 90 seconds: "… per 90 seconds" | E2E |
| RUL-12 | Edit, change nothing, "Save changes" | "Nothing changed." Nothing is sent | E2E |
| RUL-13 | Edit the attempts only | Only that field is sent. The page shows the new limit, and its history "Rule changed" | E2E |
| RUL-14 | Duplicate | "Copy of …", in dry-run, with "A copy of …, in dry-run until you promote it." | E2E |
| RUL-15 | The list's switch, on a dry-run rule | It turns off with no dialog | E2E |
| RUL-16 | The switch, on an enforcing rule | A dialog asks to confirm. Cancel leaves it on. Confirm turns it off, and its route is no longer limited | E2E |
| RUL-17 | Promote, on a dry-run rule | "Promote … to enforcing", with its last 24 hours: checks and would-blocks, or that it checked no request. Enforce makes it Enforcing, and requests over its limit get 429 | E2E |
| RUL-18 | Delete, on an enforcing rule | The dialog names the rule and says it stops limiting. Delete returns to the list, without it, and its route is no longer limited | E2E |
| RUL-19 | A rule's page | Its settings, its traffic, its offenders (or that the store keeps none), its exemptions ("No exemption names this rule."), and its history | E2E |
| RUL-20 | Open `/dynamic-throttle/rules/999999` | "There is no rule 999999.", with a link to the list | E2E |
| RUL-21 | Open `/dynamic-throttle/rules/abc/edit` | "Page not found" | E2E |
| RUL-22 | With 500 enabled rules, create or enable one | "500 rules are enabled, the most the dashboard allows" | Pest (SPEC-018) |
| RUL-23 | Another admin changes a field this form left alone | Saving never sends it back | Vitest (SPEC-022) |
| RUL-24 | More than 100 rules | Pages of 100, with Previous and Next. A page past the last goes to the last | Vitest (SPEC-022) |

## Dashboard: Exemptions

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| EXM-01 | The list | Who, Rule (a link, or "Every rule"), Reason, Ends ("Never", a time, or "Ended …") and Added, newest first. State starts at Active | E2E |
| EXM-02 | Choose State, Subject and Rule | The URL holds them. `/exemptions?rule=<id>` opens filtered | E2E |
| EXM-03 | "Add exemption": IP `127.0.0.1`, one rule, 1 hour, a reason | It is listed, ending in an hour. That rule no longer limits `127.0.0.1`; other rules still do | E2E |
| EXM-04 | The same with "No end" | Ends "Never" | E2E |
| EXM-05 | IP `10.0.0.0/33`, or `nope` | A message under the value. Nothing is saved | E2E |
| EXM-06 | IP `0.0.0.0/0`, on every rule | A dialog: "The exemption frees every client in 0.0.0.0/0 from every rule." Confirm adds it | E2E |
| EXM-07 | Remove | A dialog names who and which rule: "Its requests are limited again." Cancel keeps it; Remove deletes it, and the limit applies again | E2E |
| EXM-08 | A user by id, or a custom scope's value | Accepted for a registered scope only | Pest (SPEC-018) |
| EXM-09 | An end time in the past | Refused | Pest (SPEC-018) |

## Dashboard: Activity (Redis or Valkey)

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| ACT-01 | A rule blocks requests | Within about 5 seconds, the live feed lists them, newest first: the time, "Blocked", the rule (a link), the method and the route's template (never the path, such as `GET e2e/{path}`), the IP, and the user ("—" for a guest) | E2E |
| ACT-02 | A dry-run rule over its limit | Rows that say "Would block" | E2E |
| ACT-03 | Pause, then cause a block | It does not show. Resume brings it | E2E |
| ACT-04 | The Outcome and Rule filters | They narrow the rows, without asking the server | E2E |
| ACT-05 | "Exempt…", on a row | The Exemptions page opens, with "Add exemption" filled with that IP and rule | E2E |
| ACT-06 | Offenders, for that rule and this hour | The clients' identity hashes and their counts, and how many clients | E2E |
| ACT-07 | A store with no feed: database, file or Memcached | "This counter store keeps no live feed. Redis and Valkey do." | Vitest (SPEC-024), Manual |
| ACT-08 | An hour with no block | "Nothing was blocked in the last hour." | Vitest (SPEC-024) |

## Dashboard: Explain

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| EXP-01 | Explain a request a rule has room for | "Allowed: the next such request goes through.", its headers, and the rule's row: "Counts it", with its identity and counter | E2E |
| EXP-02 | The same, at its limit | "Blocked with a 429 by …", naming the rule, with a link | E2E |
| EXP-03 | Explain a request, then send it | Explain counted nothing: the request's `X-RateLimit-Remaining` is what it would have been | E2E |
| EXP-04 | A path no route serves | "No route matches this request." | E2E |
| EXP-05 | A dashboard path | "Untouched: it is one of the dashboard's own routes." | E2E |
| EXP-06 | IP `nope` | A message under IP | E2E |
| EXP-07 | Fill headers in, then Explain | The page's URL holds none of the form's values | E2E |

## Dashboard: Audit log

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| AUD-01 | Create a rule, then open the Audit log | The newest entry: "Rule created", the rule as a link, who did it, and what changed | E2E |
| AUD-02 | Details, on an entry | The before and after in full, side by side, with the IP and user agent | E2E |
| AUD-03 | Turn bypass on, then off | Two entries: "Bypass on", then "Bypass off" | E2E |
| AUD-04 | Filter by action and by target `rule:<id>`; then "Clear filters" | The filters go to the server and into the URL, and `/audit?target=rule:<id>` opens filtered. Clear filters shows everything again | E2E |
| AUD-05 | More than 100 entries | "Load more" adds the next page, and goes once there are no more | Vitest (SPEC-025) |

## Dashboard: Settings

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| SET-01 | The Health card | Every check, in a table named "Checks": errors, then warnings, ok and skipped, each with its status, id and message. "Check again" asks again | E2E |
| SET-02 | The Configuration card | The counters' driver, such as "redis, connection throttle", the manifest's cache store, the custom scopes, strict mode, then each config group's keys and values | E2E |
| SET-03 | The "When the dashboard is down" card | The commands for bypass, scale, rules, status and health, and a link to the runbook | E2E |
| SET-04 | A config key the app added under `dynamic-throttle` | It shows as "(not used by the package)", never its value | Pest (SPEC-031) |

## Dashboard: access and security

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| SEC-01 | Sign in as a viewer | "Viewer". No New rule, switch, Edit, Duplicate, Promote or Delete; the rule form says "Only managers can create or change rules." No emergency button or Factor. No "Add exemption" or Remove. No "Exempt…" | E2E |
| SEC-02 | As a viewer, send a change to the JSON API | 403, with a JSON `message` | E2E, Pest (SPEC-018) |
| SEC-03 | Send a change without the CSRF token | 419 | E2E, Pest (SPEC-018) |
| SEC-04 | In production, with no gate defined | 403 for everyone, guests and users, never a redirect | Pest (SPEC-018) |
| SEC-05 | A rule named `<img src=x onerror=alert(1)>`, and an exemption's reason `<script>alert(1)</script>` | Shown as text, on every page. No script runs, and no dialog opens | E2E |
| SEC-06 | The dashboard inside another site's frame | The browser refuses it: `X-Frame-Options: DENY` and `frame-ancestors 'none'` | E2E, Pest (SPEC-020) |
| SEC-07 | 121 dashboard requests in a minute by one user | The 121st gets Laravel's 429 | Pest (SPEC-018) |

## Dashboard: how it feels to use

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| UXP-01 | Every form field | A visible label, and a hint under it. An error replaces the hint, and the field is marked invalid and points to it | E2E |
| UXP-02 | Escape, in any dialog | It closes and changes nothing. Focus goes back to the button that opened it | E2E |
| UXP-03 | Double-click Confirm in a dialog | The change is sent once | E2E, Vitest (SPEC-021) |
| UXP-04 | Empty states | Each says why: "No rule yet.", "No exemption is active.", "Nothing was blocked in the last hour.", "No change has been recorded yet." | Vitest |
| UXP-05 | Numbers | Grouped as the browser's language writes them, such as 12,345 | Vitest (SPEC-021) |

## Dashboard: accessibility

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| A11-01 | Scan every page with axe, in the light and dark themes | No serious or critical violation of WCAG 2.1 A or AA | E2E |
| A11-02 | Use the keyboard only | Tab reaches the sidebar's links, then the page's controls, in reading order, each with a visible focus ring | E2E |
| A11-03 | The page's structure | One `main`, the sidebar's `nav` named "Pages", and one `h1` per page. Every table has headers | E2E |
| A11-04 | The health status changes on Settings | It is announced | Vitest (SPEC-031) |

## Dashboard: small screens

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| MOB-01 | Each page at 390 × 844 | The page never scrolls sideways. The sidebar becomes a row of links above the page, which scrolls sideways when it must | E2E |
| MOB-02 | A dialog at 390 × 844 | It fits the screen, and its buttons can be reached | E2E |
| MOB-03 | A wide table at 390 × 844 | It scrolls inside its card | E2E |

## Compatibility and performance

| ID | Steps | Expected | Covered by |
|---|---|---|---|
| CMP-01 | PHP 8.3, 8.4 and 8.5, with Laravel 12 and 13, at the lowest and newest dependencies | The test suite passes | CI |
| CMP-02 | Counters on Redis, Valkey, Memcached, MySQL, PostgreSQL and SQLite | The store contract suite passes | CI (drivers) |
| CMP-03 | 50 concurrent requests on a limit of 10, on Redis | Exactly 10 go through | Pest (concurrency group), Manual |
| CMP-04 | PHP-FPM with APCu, and Octane | A server's workers share the rules, and no state leaks between requests | Pest (SPEC-028) |
| CMP-05 | A request's cost | As recorded in `benchmarks/results/` | Benchmarks (ADR-0016) |
