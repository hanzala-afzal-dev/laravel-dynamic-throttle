# The dashboard

The dashboard ships with the package, as Horizon's does: open `/dynamic-throttle` in your browser. There is nothing to build or publish; the package serves its own assets. Its pages arrive one by one before the first release. This page covers where it is served, who gets in, its pages, how it protects itself, and its JSON API ([ADR-0015](../adr/0015-dashboard-security-and-audit.md), [ADR-0028](../adr/0028-dashboard-react-shadcn.md), [SPEC-018](../specs/018-dashboard-access-and-guardrails.md), [SPEC-020](../specs/020-dashboard-foundation.md), [SPEC-021](../specs/021-dashboard-overview.md), [SPEC-017](../specs/017-json-api.md)).

## Where it is

At `/dynamic-throttle`, behind your `web` middleware group:

```php
// config/dynamic-throttle.php
'dashboard' => [
    'enabled' => env('DYNAMIC_THROTTLE_DASHBOARD', true),
    'path' => env('DYNAMIC_THROTTLE_DASHBOARD_PATH', 'dynamic-throttle'),
    'domain' => env('DYNAMIC_THROTTLE_DASHBOARD_DOMAIN'),
    'middleware' => ['web'],
    'rate_limit' => 120,
],
```

An empty path serves no dashboard, since it would take your site's root, and `php artisan dynamic-throttle:health` warns about it.

## Who gets in

Two gates decide: `viewDynamicThrottle` to look, `manageDynamicThrottle` to change rules, exemptions and the emergency settings. Define them in a service provider's `boot()`:

```php
use App\Models\User;
use Illuminate\Support\Facades\Gate;

Gate::define('viewDynamicThrottle', fn (User $user) => $user->is_support || $user->is_admin);
Gate::define('manageDynamicThrottle', fn (User $user) => $user->is_admin);
```

- **Until you define a gate, only the `local` environment gets in**, guests too, as with Horizon. In production, nobody does. So never run a public server with `APP_ENV=local`. Once you define one gate, a gate you left out denies.
- **Managing includes viewing,** so defining `manageDynamicThrottle` alone is enough for a team of admins.
- **Guests never get in** through a gate you define, unless its callback accepts a null user (`?User $user`).
- **A denied request gets a 403,** never a redirect to a login page your app may not have. To send guests to your login page first, add `auth`: `'middleware' => ['web', 'auth']`.

## The Overview

The first page shows what limiting does now:

- **Health:** the health report's status and its first errors and warnings. The whole report waits on the Settings page.
- **Rules:** how many are enabled, enforcing or in dry-run, and how many are disabled.
- **The last hour:** the blocks, with their share of the checks, and the would-blocks of the dry-run rules.
- **Traffic:** the blocks and would-blocks over the last hour, day or week. Hover over a bar for its time, in your time zone and in UTC, and its checks.
- **Top rules:** the five rules that blocked most in the chart's period, each linking to its page.

A request that two rules check counts in both, so the numbers are checks, not requests. The last hour comes from the counter store and refreshes every 5 seconds. The day and the week come from the hourly table, which the scheduler fills once a minute.

**The emergency controls** turn bypass or a scale factor on for 15 minutes, an hour, 4 hours, a day or with no end time, and off again, as `dynamic-throttle:bypass` and `dynamic-throttle:scale` do. Turning bypass on, or a factor below 1, asks you to confirm first, with what the change does. Viewers see the controls' state but no button. While either setting is on, every page shows a banner with a button to turn it off.

## The Rules pages

**The list** shows every rule in the order it is evaluated:
- what it applies to, who it counts per, and its limit;
- its mode and status;
- its blocks, or would-blocks, in the last hour.

Search by name or pattern, and filter by status and mode. The filters stay in the page's address, so you can bookmark or share a filtered list. A manager switches a rule on or off from its row.

**A new rule** starts from a template or blank, and always in dry-run, so it shows what it would block before it blocks anything. The templates are Login, Password reset, Sign-up, API per token, API per user and Expensive endpoint. Duplicate copies a rule into a new one, also in dry-run. An edit sends only the fields you changed.

**A rule's page** shows:
- its settings;
- its traffic over an hour, a day or a week;
- the clients it blocked most, by identity hash;
- its exemptions;
- its history from the audit log.

There, a manager can:
- **Promote** a dry-run rule, after seeing how many requests it would have blocked in the last 24 hours;
- disable or enable the rule;
- delete it. Deleting always asks first, and takes the rule's exemptions with it.

Disabling an enforcing rule, or switching it to dry-run, asks you to confirm, with what the change does.

## The Exemptions page

An exemption lets a client through one rule, or every rule, for a while or with no end: the office's IP range, a partner's user, a load test.

**The list** shows who each exemption lets through, which rule, why, and when it ends, newest first. It opens on the active exemptions. Filter by state (active, expired or all), by subject (IP, user or custom) and by rule, or the exemptions on every rule. A rule's page links to its own exemptions here.

**Add exemption,** for a manager, asks:
- who: an IP address or a CIDR range, a user's id, or a custom scope's name and value;
- which rule, or every rule;
- for how long: 1 hour, 1 day, 7 days, 30 days or no end;
- why, which the audit log keeps.

A range wider than a /8 for IPv4, or a /32 for IPv6, asks you to confirm first. There is no edit: remove an exemption and add another, so the audit log shows each change. Remove asks first, naming who and which rule.

## The Activity page

Who the rules block right now, from where, and on which route.

**The live feed** lists the last hour's blocks and would-blocks, newest first: when, the outcome, the rule, the method and the route's template, the client's IP and the user. New ones arrive every 5 seconds while the tab is visible. The page holds the newest 500. Pause keeps the rows still while you read, and Resume fetches what came meanwhile. Filter by outcome and by rule.

- It needs Redis or Valkey, and `telemetry.live_feed` on, as it is by default ([Traffic and blocks](telemetry.md)). The page says so when either is missing. When telemetry samples requests, the page says it shows about one block in N.
- It shows raw IPs and users to viewers too, for an hour. Turn the feed off if your app must not.
- **Exempt…,** for a manager, opens the Exemptions page's dialog with that IP and rule filled in, so the confirmation and the audit entry are the same as anywhere else.

**Offenders** shows the clients any rule blocked most, this hour or the last, by identity hash, and how many different clients it blocked. A rule's own page shows the same for that rule.

## The Audit log page

Every change to the rules, the exemptions and the emergency settings, from the dashboard and the CLI alike, newest first: when, who, what, on which target, and what changed, such as `max_attempts: 5 → 10`. **Details** shows the full before and after side by side, with the request's IP and user agent.

Filter by action, by target such as `rule:12`, by actor such as `cli:deploy` or `user:App\Models\User:42`, and by time, entered in your browser's zone. The filters stay in the page's URL, so you can share a filtered view; a rule's History links to its own entries. **Load more** fetches the next 100. The page only reads: the log is append-only.

## The Explain page

What the rules would do with a request, and why, as [`dynamic-throttle:explain`](explain.md) prints it. Nothing is counted or changed, and viewers can use it.

Give the method and the path, and optionally the client's IP, a user's id with its guard, and headers, one `Name: value` a line. They travel in the request's body, never in the URL, since a header can hold a token; the answer shows tokens and header values masked to their last 4 characters.

- **The decision** the next such request gets, in words: allowed, blocked with a 429 by a named rule, a 503 or through anyway past a rule that could not be checked, or untouched and why, with the rate-limit headers it would carry.
- **The matching rules,** in the order they run: each one's limit under the scale factor, its scope, what it does with the request (counts it, exempt, skipped, ignored over the cap, failed, not reached), the identity it counts, and its counter: the count, what remains, and whether the next request goes through.
- **The route** and its middleware in running order, with whether `dynamic.throttle` runs after authentication, so user rules see the user; and the manifest, bypass, the scale factor and the counters it read.

## The Settings page

Everything on it only reads, for viewers and managers alike:

- **Health:** every check of `dynamic-throttle:health`, errors first, with what each looks for and what to do. See [Health checks](health.md).
- **Configuration:** the counters' driver, as `dynamic-throttle:status` names it, the manifest's cache store, the custom scopes and strict mode, and `config('dynamic-throttle')` as your app resolved it from its files and environment. A key you add that the package does not define shows as `(not used by the package)`, never its value. To change a value, change the config or the environment, and deploy.
- **When the dashboard is down:** the Artisan commands that do what the dashboard does, and a link to the [runbook](../runbook.md).

## How it protects itself

- **No rule ever applies to the dashboard,** even a `*` rule, and even with `dynamic.throttle` as global middleware: the package skips its routes by name, and before routing, by its path. So a bad rule can never lock you out of the place where you would fix it. An app route that also matches under that path, such as `{locale}/login`, stays limited.
- **Laravel's own `throttle` limits it** to `rate_limit` requests a minute per user, or per IP for a guest, 120 by default; 0 turns the limit off.
- **Its responses are never cached, never framed,** and their type is never guessed: `Cache-Control: no-store`, `X-Frame-Options: DENY` and `X-Content-Type-Options: nosniff`. Only its assets are cached, for a year, under names that change with each release.
- **Its pages send a strict Content Security Policy:** scripts and styles from your own site only, nothing inline, and no framing. If your app already sets a policy on the response, the package keeps yours, and then your policy must allow the dashboard's assets and the nonce in its `<meta name="csp-nonce">` tag.
- **Every change needs `manageDynamicThrottle`,** whatever the route: any request but GET, HEAD and OPTIONS.

## What it refuses

Everything the dashboard saves is checked first, so a rule saved there always compiles:

- **Rules:** a name of up to 100 characters, a pattern of up to 255 without spaces or a trailing slash, 1 to 4,294,967,295 attempts, a window of 1 second to 1 day, a header scope with a valid header name, a custom scope your app registered, and a response override of at most 4 KB. **At most 500 rules can be enabled.**
- **Exemptions:** an IP address or a CIDR range, a user as `App\Models\User:42` or by id alone as your default guard's model, a custom scope your app registered, a rule that exists, and an expiry in the future.
- **Emergency settings:** the same durations and factors as the CLI.

**Dangerous changes need confirmation,** in every environment: disabling, deleting or dry-running an enforcing rule, turning bypass on, a factor below 1, and an IP exemption wider than a /8 for IPv4 or a /32 for IPv6. The dashboard asks you in a dialog. Its JSON API answers 409 without `"confirm": true`, with a message that says what the change would do, such as "Bypass lifts every limit until 2027-01-15 15:30 UTC."

## The JSON API

The dashboard's pages read and change everything through a JSON API under `{path}/api`, which you can call too, for example from a script with a session. It is internal until it is declared stable, so its shape may still change before 1.0 ([SPEC-017](../specs/017-json-api.md)).

| Endpoint | Gate | Does |
|---|---|---|
| `GET api/me` | either | What you may do |
| `GET api/rules`, `GET api/rules/{id}` | either | Rules in evaluation order, 100 a page, narrowed by `search` (name or pattern, ignoring case; `%` and `_` match themselves), `enabled`, `mode` and `ids` (up to 100, comma-separated); one rule with its exemptions |
| `GET api/rules/counts` | either | The enabled rules by mode, enforcing or dry-run, and the disabled ones |
| `POST api/rules`, `PATCH api/rules/{id}`, `DELETE api/rules/{id}` | manage | Create, change or delete a rule |
| `GET api/exemptions` | either | Exemptions, newest first, 100 a page, narrowed by `state` (`active` or `expired`), `subject` (`ip`, `user` or `custom`) and `rule` (an id, or `none` for those on every rule) |
| `POST api/exemptions`, `DELETE api/exemptions/{id}` | manage | Create or delete an exemption |
| `GET api/settings` | either | Bypass and the scale factor |
| `PUT api/settings/bypass`, `PUT api/settings/scale` | manage | What `dynamic-throttle:bypass` and `dynamic-throttle:scale` do |
| `GET api/metrics?period=hour` | either | Each rule's requests, blocks and would-blocks: per minute for the last hour, or per hour for a `day` or a `week` |
| `GET api/rules/{id}/offenders` | either | The rule's top clients by identity hash, this hour or `?hour=previous` |
| `GET api/feed` | either | The live feed: the last hour's newest 500 blocks and would-blocks, newest first, with each one's time, rule and its name, method, route template, client IP and user; `?since=` (Unix milliseconds) keeps only the newer ones. `null` on a store that keeps none, and empty with `telemetry.live_feed` off |
| `GET api/audit` | either | The audit log, newest first, 100 a page through `next_cursor`; `?action=`, `?target=`, `?actor=`, `?from=` and `?until=` narrow it |
| `GET api/config` | either | The configuration servers run with: the counters' driver, the manifest store, the package's config, the custom scopes and strict mode |
| `GET api/health` | either | What `dynamic-throttle:health --json` prints |
| `POST api/explain` | either | What `dynamic-throttle:explain --json` prints, with tokens and header values masked |

```bash
curl -X PATCH https://example.com/dynamic-throttle/api/rules/12 \
  -H 'Accept: application/json' -H 'Content-Type: application/json' \
  -H "X-XSRF-TOKEN: $XSRF" -b cookies.txt \
  -d '{"enabled": false, "confirm": true}'
```

- **Every change is recorded** in the audit log, with you as the actor, your IP and your user agent, in the same transaction as the change.
- **A change answers with what it saved,** `"changed"`, and `"manifest"`: `{"compiled": true, "version": 41}`, or `{"compiled": false, "error": "…"}` when the change is saved but servers keep the last manifest until the next compile.
- **Errors are JSON:** 422 with each field's message, 409 for a change that needs `"confirm": true`, and 503 when a table is missing or the counter store is down. Send `Accept: application/json`, so a 403 or a 404 is JSON too.
- **Explain is a POST,** so a bearer token you test never lands in a URL or an access log. It needs only the view gate, and its answer shows a token's or a header value's last 4 characters, and their hashes in full.

## When it is unreachable

The CLI can do everything an incident needs: list, disable and enable rules, bypass and scale ([during an incident](incidents.md), [runbook](../runbook.md)).
