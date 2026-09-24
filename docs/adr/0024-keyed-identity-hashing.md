# ADR-0024: Identities are hashed with a key derived from APP_KEY

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2
- Amends: [ADR-0009](0009-scopes-and-identity.md) ("Identities are hashed with SHA-256")
- Related: SPEC-008, ADR-0013

## Context

- ADR-0009 hashes every identity with SHA-256 before it reaches a cache key, so raw tokens, emails and IPs never land in keys or metrics.
- Plain SHA-256 only hides values that are hard to guess, such as tokens.
- Many identities are easy to guess:
  - There are about 4.3 billion IPv4 addresses.
  - User ids are usually sequential numbers.
- Hashing every candidate and comparing takes seconds. So anyone who can read the cache, or a metrics export, recovers the IP or user behind each counter. That defeats ADR-0009's goal.

## Decision

- Identities are hashed with HMAC-SHA-256. The key is derived from the app's `APP_KEY` (`app.key`), and is used for nothing else.
- Every server of an app shares `APP_KEY`, so all servers compute the same identity for the same client.
- Without an `APP_KEY`, identities fall back to plain SHA-256, so rate limiting keeps working. The health check reports it (SPEC-016).

## Reasoning

- An HMAC costs the same as a plain hash at this size.
- Recovering an identity then needs the key, not just the cache.
- `APP_KEY` is already the app's secret. It exists on every server, and every deployment already manages it, so there is no new configuration.

## Alternatives considered

- **Plain SHA-256, as ADR-0009 wrote.** It is reversible for IPs and numeric ids. Rejected.
- **A fixed salt shipped with the package.** Anyone with the package's source has it. Rejected.
- **A dedicated `DYNAMIC_THROTTLE_KEY`.** It is one more secret to generate, share across servers and forget. Rejected for now: see the fallback.

## Fallback

- If some deployments cannot share `APP_KEY` across servers, or do not want counters tied to it, add an optional dedicated key in config.
- **Revisit when:** users report counters that are not shared across servers, or resets after key rotation that hurt.

## Consequences

- Good: a leaked cache or metrics export no longer reveals who was counted.
- Bad: rotating `APP_KEY` resets every counter once. Windows are at most 24 hours, so the effect ends within a day.
  - Laravel's `app.previous_keys` are not used for identities.
- Bad: the dashboard and metrics can never show which IP or user an identity belongs to. That was already ADR-0009's intent.

## Verification

- SPEC-008 AC-15: the same identity under two app keys hashes differently, and neither hash is the plain SHA-256.
- SPEC-008 AC-14: no raw identifier appears in any key the store writes.
