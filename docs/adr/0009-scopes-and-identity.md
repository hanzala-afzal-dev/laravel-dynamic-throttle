# ADR-0009: Scopes and client identity

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Amended by: [ADR-0020](0020-laravel-ecosystem-coexistence.md) (`scopes.user.guards`, per-tenant limits through a custom scope), [ADR-0022](0022-skip-rules-without-identity.md) (`on_missing_identity` for built-in scopes), [ADR-0024](0024-keyed-identity-hashing.md) (identities hashed with a key derived from `APP_KEY`)
- Related: ADR-0004, ADR-0008

## Context

- A scope decides "who" a counter belongs to.
- The first draft hard-coded `role` as `$request->user()->role`, which is an app-specific assumption.
- It hashed identities with md5.
- It trusted any request header.

## Decision

### Built-in scopes

| Scope | Identity | When missing |
|---|---|---|
| `ip` | Client IP (see IPv6 below) | — |
| `user` | `$request->user()->getAuthIdentifier()` | `guest:<ip>` |
| `api_token` | Bearer token | `anon:<ip>` |
| `header` | Value of the named header | `missing:<ip>` |
| `global` | One counter for everyone | — |
| `custom:<name>` | Resolver registered by the app | Configurable |

### Custom resolvers

The app registers resolvers, for example:

```php
DynamicThrottle::scope('plan', fn (Request $r) => $r->user()?->plan);
```

This replaces the hard-coded `role` scope. If a resolver returns `null`, the rule either falls back to the IP (default) or is skipped for that request, set per rule with `on_missing_identity`.

### Identity handling

- Identities are hashed with SHA-256 before being used in keys. Raw tokens or emails never land in cache keys or metrics.
- **IPv6:** the `ip` scope groups IPv6 addresses by prefix. Config `ipv6_prefix`, default `/64`, because one client usually controls a whole /64.
- **Proxies:** the IP comes from `$request->ip()`, so the app's trusted-proxy settings decide it. The docs explain this, and the health check warns when one IP dominates traffic, which is often a load balancer IP caused by a proxy misconfiguration.
- **Header scope warning:** clients can send any header value. A client rotating values bypasses the limit. The dashboard shows a warning, and the docs recommend it only for headers set by a trusted gateway.

## Reasoning

- Custom resolvers cover roles, plans, tenants and organizations without the package guessing the app's data model.
- SHA-256 costs almost nothing at this size and avoids leaking identifiers.
- /64 grouping stops the most common IPv6 bypass.
- Using Laravel's own `ip()` means one place to configure proxies.

## Alternatives considered

- **Hard-coded `role`/`plan` scopes.** Assumes attribute names. Rejected.
- **md5 or xxh128.** Fine for collisions, but SHA-256 is the safer default for token-derived keys. Rejected.
- **Full IPv6 addresses.** Trivial to bypass. Rejected as the default; available with `ipv6_prefix = 128`.

## Fallback

- If SHA-256 shows up in profiles (unlikely), switch to xxh128 for non-secret scopes only (`ip`, `user`) in a minor release. Counter keys change, so counters reset once.
- If resolvers are too slow (for example, database lookups inside them), document "resolvers must be cheap" and memoize per request.
- **Revisit when:** users request a scope that cannot be expressed as a resolver.

## Consequences

- Good: flexible, private and safe by default.
- Bad: correctness depends on the app's proxy settings, which is outside our control but made visible.

## Verification

- Unit tests per scope, including every "when missing" case.
- IPv6 tests: addresses in the same /64 share a counter; different /64s do not.
- Test: no raw token appears in any cache key.
- Health-check test for the dominant-IP warning.
