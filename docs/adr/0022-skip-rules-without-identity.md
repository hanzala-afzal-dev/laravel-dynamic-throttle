# ADR-0022: Rules can skip requests without an identity, on every scope

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2
- Amends: [ADR-0009](0009-scopes-and-identity.md) (`on_missing_identity` for built-in scopes)
- Related: SPEC-005, SPEC-008

## Context

- ADR-0009 gives each built-in scope a fixed fallback when the identity is missing:
  - `user` falls back to `guest:<ip>`
  - `api_token` to `anon:<ip>`
  - `header` to `missing:<ip>`
- Only custom scopes can choose, per rule, between that IP fallback and skipping the rule (`on_missing_identity`).
- A common policy cannot be expressed that way: "limit logged-in users to 1,000 requests a minute, and leave guests to another rule". A `user` rule always counts guests too, under `guest:<ip>`.
- SPEC-005 adds the `on_missing_identity` column to every rule, so the schema does not depend on this decision.

## Decision

- `on_missing_identity` applies to every scope that can miss an identity: `user`, `api_token`, `header` and `custom`.
  - `ip` (the default) keeps ADR-0009's fallback for that scope.
  - `skip` means the rule does not apply to a request without that identity: it neither counts nor rejects the request.
- The `ip` and `global` scopes always have an identity, so the column does not affect them.

## Reasoning

- "Only limit logged-in users" and "only limit API clients" are ordinary policies. Skipping keeps them to one rule each, without a workaround.
- The default stays ADR-0009's behavior, so existing expectations do not change.

## Alternatives considered

- **Keep `skip` for custom scopes only.** Users would need a custom resolver that re-implements the `user` scope just to skip guests. Rejected.
- **Skip by default.** Guests would silently escape user rules. That is exactly the middleware-order failure ADR-0004 warns about, now as a default. Rejected.

## Fallback

- If users confuse `skip` with an exemption, the dashboard shows it as "Applies to: authenticated requests only". **Revisit when:** issues show the confusion.

## Consequences

- Good: user and token policies need no custom resolvers.
- Bad: a `skip` rule that is attached before the auth middleware applies to nobody. ADR-0004's health check, which warns when a user-scoped rule sees mostly guests, now also covers `skip` rules.

## Verification

- SPEC-008 tests:
  - A `user` rule with `skip` does not count or reject a guest request.
  - With `ip`, a guest request is counted under `guest:<ip>`.
  - The same pair for `api_token` and `header`.
