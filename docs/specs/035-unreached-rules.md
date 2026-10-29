# SPEC-035: Say when a rule can never apply

- Status: In progress
- ADRs: ADR-0008, ADR-0017, ADR-0020
- Roadmap phase: the betas, before 0.1.0

## Problem

A rule applies only on routes that run `dynamic.throttle`, outside `except` (ADR-0020). A rule whose pattern matches only other routes never counts a request, and nothing says so. In a new-user test of `0.1.0-beta.2` (issue #149), the README attached the middleware to the `api` group only, and the dashboard's Login template made a rule for `POST login`, a `web` route. `dynamic-throttle:explain POST /login` said "untouched", but every health check was ok, and the rule's page said only that it checked no request. The user believes the login is limited.

## Behavior

A new health check, `unreached`, in `dynamic-throttle:health`, on the Overview's health card and on the Settings page. It matches each route of the app against the rules as a request to that route would match them, and warns about each rule that matches routes, none of which limits it: they do not run `dynamic.throttle`, or `except` covers their paths. It names the rules and their routes, and says to attach the middleware to those routes' groups.

## Decisions

1. **The id is `unreached`, listed after `attached`,** with the checks of the routes.
2. **A warning, not an error.** The other rules still work. `--strict` catches it for deploys.
3. **A route matches a rule as a request to it would:** for each of the route's methods, its URI goes through the middleware's `RuleMatcher`, with each parameter as written, such as `api/users/{user}`, and once more without its optional parameters. So `*` matches a parameter, and the method and pattern rules are the middleware's own.
4. **A route limits a rule when it runs `dynamic.throttle`,** on the route, through its group, or as global middleware, **and `except` does not cover its path**, as `explain` decides.
5. **Only a rule that matches routes and is limited on none is reported.** A rule that matches no route stays quiet. Its pattern may name a parameter's value, such as `api/users/42/*`, which decision 3 cannot match against `api/users/{user}/posts`. Or it may name a path the app adds later. Warning about those would cry wolf.
6. **Skipped when no route runs `dynamic.throttle`,** since the `attached` check already warns, and every rule would be named. Skipped without a manifest, whose rules it needs, as `middleware-order` is.
7. **Dry-run rules count:** a dry-run rule that never applies records nothing either. Disabled rules are not in the manifest, so they are not named.
8. **Not on the rule's page, for now.** The rule's page would need the JSON API to read the app's routes for each rule. The Overview's health card shows the warning on the dashboard's first page. Revisit if beta users ask.
9. **Exact messages:**
   - warning, one rule: `1 rule matches only routes that do not run dynamic.throttle, or that except covers, so it never applies: rule 2, Login, on POST login. Attach dynamic.throttle to those routes' groups, such as web for login and sign-up forms, or list the groups in dynamic-throttle.middleware.auto_attach.`
   - warning, more rules: `2 rules match only routes that do not run dynamic.throttle, or that except covers, so they never apply: rule 2, Login, on POST login; rule 5, Sign-up, on POST register. Attach …` The first ten rules, then "and N more", and for each rule its first ten routes, as the other route checks list them.
   - ok: `Every rule that matches a route of the app matches one that dynamic.throttle limits.`
   - ok, no rule: `There is no rule.`
   - skipped, no route runs it: `No route runs dynamic.throttle: see the attached check.`
   - skipped, no manifest: the manifest's problem, as `middleware-order` says it.

## Acceptance criteria

- **AC-1** Given `dynamic.throttle` on the `api` group only, a `POST login` route in `web` and an enabled rule for `POST login`, the check warns with the one-rule message of decision 9, and `dynamic-throttle:health --strict` exits 1.
- **AC-2** Given the same rule and `dynamic.throttle` on `web` too, the check is ok.
- **AC-3** Given a rule that matches one route that runs `dynamic.throttle` and one that does not, the check is ok.
- **AC-4** Given a rule that matches only a route whose path `except` covers, such as `up`, though the route runs `dynamic.throttle`, the check warns about it.
- **AC-5** Given a rule that matches no route, by its pattern or by its method, the check is ok. This includes a rule naming a parameter's value, `api/users/42/*`, beside an unlimited route `api/users/{user}/posts`.
- **AC-6** Route parameters match the rules' wildcards: a rule `api/users/*/export` matches `api/users/{user}/export`, and a rule `posts` matches `posts/{post?}`, without its optional parameter. With those routes unlimited, the check warns.
- **AC-7** A `GET` rule matches a `GET|HEAD` route, a `*` rule matches every method, and a `POST` rule does not match a `GET|HEAD` route.
- **AC-8** A dry-run rule is checked as an enforcing one. A disabled rule is never named.
- **AC-9** With 12 such rules, the warning names the first ten, then "and 2 more", and says "12 rules".
- **AC-10** With `dynamic.throttle` as global middleware, every route outside `except` limits the rules, so a rule on any such route is ok.
- **AC-11** Without a route that runs `dynamic.throttle`, the check is skipped with its message. Without a manifest, it is skipped with the manifest's problem. Without a rule, it is ok with its message.
- **AC-12** The report lists `unreached` after `attached`, the dashboard's `GET api/health` includes it, and the Settings page shows what it looks for.

## Interfaces and files

- `src/Support/Health/Checks/UnreachedCheck.php`: the check. Listed in `HealthChecks`.
- `resources/dashboard/lib/settings-page.ts`: what the check looks for on the Settings page, and `dist/` rebuilt.
- `docs/guide/health.md`, `docs/guide/middleware.md`, `docs/roadmap.md`, `CHANGELOG.md`.

No config key, command, event or schema changes. A new check id is not a breaking change (ADR-0017).

## Out of scope

- The rule's page (decision 8).
- Warning about a rule that matches no route (decision 5).
- The rule form warning before a rule is saved: it would need the same API.

## Edge cases and failure modes

- **A route whose middleware cannot be read,** such as one whose controller cannot be built: `routeList()` throws, and the check errs with the other route checks (SPEC-016 AC-3).
- **The package's own routes** are left out, as for every route check: the dashboard is never limited.
- **The root route** has the URI `/`, which the matcher takes as it takes a request to `/`.
- **Many routes and rules:** the matcher's index keeps each lookup to the rules that share a route's first segment, and the check runs only on the command and the dashboard's health endpoint, never on a request.

## End-to-end verification

- `composer test -- --filter=HealthUnreached`, then `composer test` and `composer analyse`.
- `npm run test`, `npm run build`.
- In a fresh Laravel app with Breeze or a `POST login` route in `web`, the middleware on `api` only, and a rule from the Login template: `php artisan dynamic-throttle:health` warns about it, and attaching the middleware to `web` makes it ok.

## Open questions

None.
