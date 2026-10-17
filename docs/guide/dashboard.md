# The dashboard

The dashboard ships with the package, as Horizon's does: open `/dynamic-throttle` in your browser. There is nothing to build or publish; the package serves its own assets. Its pages arrive one by one before the first release. This page covers where it is served, who gets in, how it protects itself, and its JSON API ([ADR-0015](../adr/0015-dashboard-security-and-audit.md), [ADR-0028](../adr/0028-dashboard-react-shadcn.md), [SPEC-018](../specs/018-dashboard-access-and-guardrails.md), [SPEC-020](../specs/020-dashboard-foundation.md), [SPEC-017](../specs/017-json-api.md)).

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
| `GET api/rules`, `GET api/rules/{id}` | either | Rules in evaluation order, 100 a page; one rule with its exemptions |
| `POST api/rules`, `PATCH api/rules/{id}`, `DELETE api/rules/{id}` | manage | Create, change or delete a rule |
| `GET api/exemptions` | either | Exemptions, newest first, 100 a page; `?rule={id}` for one rule's |
| `POST api/exemptions`, `DELETE api/exemptions/{id}` | manage | Create or delete an exemption |
| `GET api/settings` | either | Bypass and the scale factor |
| `PUT api/settings/bypass`, `PUT api/settings/scale` | manage | What `dynamic-throttle:bypass` and `dynamic-throttle:scale` do |
| `GET api/metrics?period=hour` | either | Each rule's requests, blocks and would-blocks: per minute for the last hour, or per hour for a `day` or a `week` |
| `GET api/rules/{id}/offenders` | either | The rule's top clients by identity hash, this hour or `?hour=previous` |
| `GET api/audit` | either | The audit log, newest first, 100 a page through `next_cursor`; `?action=`, `?target=`, `?actor=`, `?from=` and `?until=` narrow it |
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
