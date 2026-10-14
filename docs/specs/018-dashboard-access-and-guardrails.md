# SPEC-018: Dashboard access, validation and guardrails

- Status: In progress
- ADRs: ADR-0004, ADR-0011, ADR-0015, ADR-0018
- Roadmap phase: 4

## Problem

The dashboard and its JSON API (SPEC-017) can switch off a login route's protection, or block every API request. Before any of their endpoints exists, the package needs the frame they live in: where they are served, who may read and who may change, how a change is checked before it is saved, and how the dashboard keeps the package's own rules from locking admins out.

## Behavior

- **One route group** at `dashboard.path` (default `dynamic-throttle`), on `dashboard.domain` when it is set, behind `dashboard.middleware` (default `['web']`, decision 1). Its routes are named `dynamic-throttle.*`. With `dashboard.enabled` off, no route is registered. After the app's middleware, the group runs:
  - the package's authorization (the gates, below);
  - Laravel's own `throttle`, with the named limiter `dynamic-throttle`: `dashboard.rate_limit` requests a minute (default 120) per user, or per IP for a guest (decision 4);
  - headers on every response: `Cache-Control: no-store` and `X-Content-Type-Options: nosniff`.
- **Two gates** (ADR-0015): `viewDynamicThrottle` to read, `manageDynamicThrottle` to change.
  - A read route needs either gate, and a write route needs `manageDynamicThrottle` (decision 7).
  - A gate the app has not defined allows the `local` environment only, guests too, as Horizon's does. It is checked on each request, so the order of the service providers does not matter (decision 2).
  - A gate the app defines decides alone, in every environment. Laravel's gates deny a guest unless the gate's callback accepts a null user.
  - A denied request gets a 403, as JSON when it asks for JSON, and is never redirected to a login page (decision 8).
- **`GET {path}/api/me`**, the first endpoint, so the dashboard's script shows only what the user may do: `{"can": {"view": true, "manage": false}}`. It needs either gate.
- **No rule ever applies to the dashboard,** by its routes' names, and by its path, so that `dynamic.throttle` as global middleware, which runs before routing, skips it too (decision 5, ADR-0004 point 5). `dynamic-throttle:explain` says so for the path.
- **Validation** checks what the API accepts before anything is saved, with the messages of Laravel's 422 response. The classes are ready for SPEC-017's endpoints to call. A rule that passes always compiles (decision 6):
  - **Rules:** a name of 1 to 100 characters; a description of at most 1,000; `method`, `scope`, `mode`, `on_failure` and `on_missing_identity` from their enums; a pattern of 1 to 255 characters without spaces or control characters; `max_attempts` from 1 to 4,294,967,295; `window_seconds` from 1 to 86,400; `priority` from 0 to 4,294,967,295; `response_override` null or a JSON object of at most 4 KB once encoded; `enabled` a boolean.
  - **A rule's scope parameter:** required for `header` (a header name, 1 to 64 letters, digits and `-`) and for `custom` (a scope the app registered with `DynamicThrottle::scope()`), and absent for the other scopes.
  - **At most 500 enabled rules** (ADR-0015, ADR-0007): creating an enabled rule, or enabling one, when 500 are enabled is refused, with the count.
  - **Exemptions:** an `ip` exemption names an address or a CIDR range, a `user` one a user id, and a `custom` one a registered scope and its value; an optional rule that exists; an optional expiry in the future; a reason of at most 255 characters.
  - **Settings:** bypass on or off, and a scale factor from 0.1 to 10 with at most two decimals, or off. Each with `for`, as the CLI takes it (`90s`, `30m`, `2h` or `1d`, up to 365 days, one hour by default), or `forever`. The API and the CLI use the same parsers, so they accept the same values.
- **Dangerous changes need `"confirm": true`** in the request, in every environment (decision 3, ADR-0015 point 4): disabling or deleting an enforcing rule, turning bypass on, and a factor below 1. Without it, the API answers 409 with a message that says what the change does, which the dashboard's dialog shows.

## Acceptance criteria

### Routes and access
- **AC-1** With the defaults, `GET /dynamic-throttle/api/me` exists, named `dynamic-throttle.api.me`, behind the `web` group. With `dashboard.path` set to `ops/throttle`, it is at `/ops/throttle/api/me`; with `dashboard.domain` set, only on that domain. With `dashboard.enabled` off, or an empty path, no route named `dynamic-throttle.*` exists, and with an empty path the `config` health check warns.
- **AC-2** With no gate defined, in `local`: a guest and a user both get 200 and `{"can": {"view": true, "manage": true}}`. In `production`: both get 403.
- **AC-3** With both gates defined, in `production` and in `local`, the matrix holds for `api/me`:

  | Who | `viewDynamicThrottle` | `manageDynamicThrottle` | Status | `can` |
  |---|---|---|---|---|
  | guest | callback needs a user | callback needs a user | 403 | |
  | viewer | allows | denies | 200 | view only |
  | manager | denies | allows | 200 | view and manage |
  | neither | denies | denies | 403 | |

- **AC-4** Only `manageDynamicThrottle` defined: a manager gets 200, and in `production` everyone else gets 403, since the undefined view gate allows `local` only.
- **AC-5** A denied JSON request gets a 403 JSON body with a `message`; a denied HTML request gets the app's 403 page. Neither redirects.
- **AC-6** A write route (a test route registered in the group, until SPEC-017's exist) needs `manageDynamicThrottle`: a viewer gets 403. A POST without a CSRF token gets 419, as any `web` route does.
- **AC-7** The 121st request in a minute from one user gets Laravel's 429, and another user's first request gets 200. Guests are counted by IP. `dashboard.rate_limit` 0 turns the limit off.
- **AC-8** Every response of the group, 403 and 429 included, carries `Cache-Control: no-store` (with `private`, as Laravel writes it) and `X-Content-Type-Options: nosniff`.

### Self-protection
- **AC-9** A `*` rule of 1 request a minute, for every method: the dashboard answers its 2nd, 3rd and 10th request with no 429 and no rate-limit header, with `dynamic.throttle` on the `web` group, and as global middleware.
- **AC-10** With `dashboard.path` changed, the new path is skipped and the old one is not. The path check covers `{path}` and `{path}/…`, never a path that only starts with the same letters, such as `dynamic-throttle-other`.
- **AC-11** `dynamic-throttle:explain GET /dynamic-throttle/api/me` says the package's own routes go through untouched.

### Validation
- **AC-12** A rule's valid input, at each boundary (1 and 4,294,967,295 attempts, 1 and 86,400 seconds, a 255-character pattern, a 4,096-byte override), passes, and once saved, it compiles: the manifest holds it.
- **AC-13** Each invalid input is refused with a 422 message naming its field: an empty or 101-character name, an unknown enum value, a pattern with a space, 0 attempts, 0 or 86,401 seconds, a negative priority, an override that is a list or 4,097 bytes, a `header` rule without a header name or with `X Bad`, a `custom` rule with a scope the app did not register, and an `ip` rule with a scope parameter.
- **AC-14** With 500 enabled rules, creating an enabled rule and enabling a disabled one are refused with "500 rules are enabled, the most the dashboard allows"; creating a disabled one is accepted.
- **AC-15** Exemptions: `10.0.0.0/8` and `2001:db8::/32` pass, `10.0.0.0/33` and `nope` do not; a missing rule id, an expiry in the past, and a 256-character reason are refused.
- **AC-16** Settings: the same durations and factors as the CLI pass and fail (`90s`, `1d`, `366d`, `0.1`, `10`, `0.05`, `1.234`), and `for` with `forever` together is refused.

### Guardrails
- **AC-17** Without `"confirm": true`, these get 409 and change nothing: disabling or deleting an enforcing rule, bypass on, a factor below 1. With it, they pass. Disabling a dry-run rule, bypass off, a factor of 1 or above, and enabling a rule need no confirmation.
- **AC-18** The 409's `message` says what the change does, such as "Bypass lifts every limit until 15:30 UTC."

### Hard rules
- **AC-19** The arch tests hold: the dashboard's code is not on the request path's namespaces, and the request path does not use it.

## Interfaces and files

- `config/dynamic-throttle.php`: a `dashboard` section with `enabled`, `path`, `domain`, `middleware` and `rate_limit`.
- `src/Http/Dashboard/`:
  - `DashboardRoutes.php`: registers the group, the limiter and `api/me`;
  - `Authorize.php`: the middleware for the gates, `read` or `write`;
  - `Gates.php`: what an undefined gate allows;
  - `SecurityHeaders.php`: the two headers;
  - `MeController.php`.
- `src/Http/Dashboard/Validation/`: `RuleInput.php`, `ExemptionInput.php`, `SettingInput.php`, and `Confirmation.php` for the dangerous changes.
- `src/Http/Untouched.php`: the dashboard's path too.
- `src/Console/Duration.php` and `src/Support/ScaleFactor.php`: shared with the API, unchanged.
- `docs/guide/dashboard.md`: access, the gates, the path, and what the guardrails refuse.
- Tests: `tests/Feature/Dashboard/AccessTest.php` (AC-1 to AC-8), `SelfProtectionTest.php` (AC-9 to AC-11), `RuleInputTest.php` (AC-12 to AC-14), `ExemptionAndSettingInputTest.php` (AC-15, AC-16), `ConfirmationTest.php` (AC-17, AC-18), and `tests/Arch/ArchTest.php` (AC-19).

## Out of scope

- The endpoints for rules, exemptions, settings, metrics, health and explain (SPEC-017), which use this spec's validation and confirmation.
- The audit entries of the API's changes, and reading the log (SPEC-019).
- The pages, the layout, the assets and the Content Security Policy (SPEC-020).
- A policy class instead of the gates (ADR-0015's fallback), and approval by a second person (after 1.0).

## Edge cases and failure modes

- **The app's `web` group throttles too,** with its own limiter. Both apply; the dashboard's does not replace the app's.
- **The app defines a gate after the request starts,** such as in a middleware: the gates are read on each request, so it applies.
- **The app has no `web` group,** such as a stateless API: `dashboard.middleware` can name its own stack. Without a session, the CSRF check and the user come from that stack.
- **A path with a leading or trailing slash** (`/ops/throttle/`) is trimmed. An empty path serves no dashboard, since it would take the site's root, and the `config` health check warns about it. Refusing it at boot would take the whole app down for a config mistake (ADR-0020 point 1).
- **Laravel's middleware priority list** would run `throttle` before the headers, so its 429 would miss them: the headers middleware goes first in that list.
- **The cache store is down:** Laravel's `throttle` throws, as on any route the app throttles. The dashboard's limiter uses the app's default cache store, not the package's.
- **500 enabled rules, two requests at once:** both may pass the count and save 501. The limit is a guardrail against mistakes, not a hard bound; the compiler handles more (ADR-0007).

## End-to-end verification

- `composer test -- --filter=Dashboard`
- `composer test`, `composer analyse`
- In the Workbench app: open `/dynamic-throttle/api/me` locally, then with `APP_ENV=production`, then with the gates defined.

## Decisions

The maintainer vetoes any of these in the PR (ADR-0027).

1. **The routes run behind `['web']` by default, and the gates decide** (the maintainer chose it on 2026-10-14, amending ADR-0015 point 2). An undefined gate allows `local` only, and a defined gate denies guests. Rejected: `['web', 'auth']`, which fails with "Route [login] not defined" in apps without a login page, and needs a login even in local development.
2. **An undefined gate allows the `local` environment, guests too,** checked per request, as Horizon does. Rejected: `testing` as well, since tests define their gates.
3. **The server refuses a dangerous change without `"confirm": true`,** in every environment, not only in production as the CLI does: the dashboard is always a person at a page. Rejected: dialogs in the page only, which a script or a bug in the page skips.
4. **The dashboard protects itself with Laravel's `throttle`:** 120 requests a minute per user, about six pages polling every three seconds. Rejected: no limit, which leaves a stolen session free to hammer the API.
5. **No rule applies to the dashboard's path either,** not only to its routes' names, because global middleware runs before routing (found by SPEC-014's review).
6. **Validation is at least as strict as the compiler,** so a rule saved through the API always compiles, and stricter where a mistake would only show at request time: an unregistered custom scope, a header name with spaces.
7. **`manageDynamicThrottle` also lets a user read,** so an app can define only the manager gate.
8. **A denied request gets a 403, never a redirect:** the dashboard has no login of its own, and the app's login route may not exist.
9. **The default path is `dynamic-throttle`,** the package's name, as Horizon's is `horizon`.

## PRs

Stacked on each other, each green on its own (ADR-0027).

| PR | Branch | Adds | Covers |
|---|---|---|---|
| 1 | `phase-4/spec-018-1` | This spec, ADR-0015's amendment, and the roadmap's line | Decisions |
| 2 | `phase-4/spec-018-2` | The config, the route group, the gates, the limiter, the headers and `api/me` | AC-1 to AC-8 |
| 3 | `phase-4/spec-018-3` | The dashboard's path in the middleware and in explain | AC-9 to AC-11, AC-19 |
| 4 | `phase-4/spec-018-4` | The rules' validation and the 500-rule limit | AC-12 to AC-14 |
| 5 | `phase-4/spec-018-5` | The exemptions' and the settings' validation, the confirmation, the guide page, the changelog, and the spec's close | AC-15 to AC-18 |

## Open questions

None. Decision 1 was asked first, since it amends an ADR.
