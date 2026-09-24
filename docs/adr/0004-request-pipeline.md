# ADR-0004: Where the middleware runs

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Amended by: [ADR-0020](0020-laravel-ecosystem-coexistence.md) (the `except` list, `middleware.after`, the two-limiter check, nothing attached by default)
- Related: ADR-0009

## Context

- User-based scopes need the authenticated user. If the middleware runs before authentication, `$request->user()` is `null`, and every user rule silently becomes an IP rule.
- Laravel's own throttle middleware hits the same trap when middleware order is wrong (see laravel/framework issue #30498).
- Global middleware runs before routing and before auth, on every request.

## Decision

1. **Primary integration is route middleware.** Register the alias `dynamic.throttle` and apply it to route groups such as `api`.
2. **Middleware priority.** On boot, the package inserts its middleware into Laravel's middleware priority list directly after the authentication middleware. When both sit in the same group, Laravel sorts ours after auth.
3. **Optional auto-attach.** Config `middleware.auto_attach` (default `[]`) lists route groups to append the middleware to, for people who do not want to edit routes at all.
4. **Terminable middleware.** Metrics are recorded in `terminate()`, after the response is sent (ADR-0013).
5. **Self-protection.** Requests to the dashboard and package asset routes are always skipped. No rule can lock admins out.
6. **Guest fallback.** A user-scoped rule with no authenticated user falls back to `guest:<ip>` (ADR-0009). A health check warns when a user-scoped rule resolves mostly guests, which usually means a middleware-order problem.
7. **Coexistence.** Laravel's `throttle` middleware keeps working. The docs advise against using both for the same purpose on the same route.

## Reasoning

- Route middleware runs after routing, so the route and the user are known.
- Priority insertion fixes the order automatically in the common case, without the user having to know about it.
- The health check turns a silent failure into a visible warning.

## Alternatives considered

- **Global middleware only.** Runs before auth, which breaks user scopes. Rejected as the default.
- **Per-route parameters** (`dynamic.throttle:rule-name`). Defeats "rules without touching routes". Rejected.
- **An event listener on `RouteMatched`.** Still runs before route middleware (auth) executes. Rejected.

## Fallback

- If priority insertion does not work in some setup (custom kernels, unusual group order), the docs include a manual snippet for `bootstrap/app.php`.
- The `dynamic-throttle:explain` command shows whether the user was resolved for a sample request.
- **Revisit when:** issues report user rules behaving like IP rules.

## Consequences

- Good: correct behavior by default for user and token scopes.
- Bad: routes outside the attached groups are not covered. The docs must say this clearly.

## Verification

- Feature test: an authenticated route with a user-scoped rule counts per user, not per IP.
- Feature test: with the middleware listed before `auth` in a group, it still runs after `auth`.
- Feature test: dashboard routes are never throttled, even with a `*` rule.
