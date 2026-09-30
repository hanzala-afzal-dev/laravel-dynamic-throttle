# ADR-0008: Rule matching and stacking

- Status: Accepted
- Date: 2026-09-23
- Roadmap phase: 2
- Amended by: [ADR-0020](0020-laravel-ecosystem-coexistence.md) (rules with equal priorities run in id order), [ADR-0023](0023-pre-index-built-at-load.md) (the pre-index is built when the manifest loads), [ADR-0025](0025-cap-counts-attempts-per-mode.md) (the cap counts attempts, per mode), [ADR-0026](0026-one-round-trip-per-request.md) (one store round trip per request)
- Related: ADR-0005, ADR-0007, ADR-0012

## Context

- The first draft applied only the **first** matching rule, and looped through every rule for every request.
- Real setups need stacking: a general API limit **and** a stricter login limit on the same request.

## Decision

### Matching

- A rule matches on HTTP method (`*` = any) plus a **path pattern**. The pattern uses Laravel's `Str::is` wildcard rules on the normalized path, without the leading slash.
- The compiler pre-indexes rules by method and by first literal path segment, so only candidate rules are tested.
- Hard limit: `matching.max_rules_per_request` (default 5). Extra matches are ignored and reported by the health check.

### Stacking

- **All** matching active rules apply, evaluated in priority order (lower number first).
- Exemptions are checked per rule before counting. Global exemptions (no rule id) skip all rules. Expired exemptions are ignored.
- The first **enforcing** rule that rejects stops evaluation.
- Rules evaluated earlier had already counted the request, so they are **compensated** (`LimiterStore::release`). A rejected request ends up counted by no rule, consistent with ADR-0006.
- Dry-run rules count every request and never reject (ADR-0012).

### Response

- Allowed requests get headers from the most restrictive enforcing rule, meaning the one with the lowest remaining:
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining`
- Rejected requests throw Laravel's `ThrottleRequestsException` with these headers, so the host app's exception handler renders the 429 like any other:
  - `Retry-After`
  - `X-RateLimit-Reset`
  - `X-RateLimit-Limit`
  - `X-RateLimit-Remaining: 0`
- If the rejecting rule has a `response_override`, that JSON body is used instead (size-limited on save).

## Reasoning

- Stacking expresses real policies ("1,000/min per user overall, 5/min per IP on login").
- Compensation keeps the mental model simple: "a blocked request doesn't use up your other limits". A strict rule's rejections cannot drain a lenient rule.
- The pre-index and the per-request cap bound the cost as the rule count grows.
- Throwing Laravel's own exception keeps 429 rendering consistent with the host app.

## Alternatives considered

- **First match wins** (firewall style). Simple, but cannot express a global limit plus a specific one. Rejected.
- **Most specific wins.** "Specific" is hard to define with wildcards and surprises users. Rejected.
- **Independent limiters without compensation** (every matched rule counts every request). Simpler and faster, but a strict rule's rejections eat into lenient rules. This is the fallback.

## Fallback

- If compensation proves buggy or too slow, switch to independent limiters (no `release`), document the semantic change, and bump the minor version (0.x).
- If path patterns are too fragile (routes renamed, localized URLs), add route-name matching (`match: route_name`) in a later minor release.
- **Revisit when:** issues show confusion about which rule blocked a request. Improve `dynamic-throttle:explain` first.

## Consequences

- Good: expressive policies, predictable counting, and bounded cost.
- Bad: under heavy concurrency, compensation leaves a brief window where a counter is inflated by one. That can cause a rare early rejection at the exact limit boundary. Documented.

## Verification

- Feature tests:
  - Two stacked rules; the stricter one rejects; the lenient rule's counter is unchanged.
  - Priority order is respected.
  - The cap is enforced.
  - Global and per-rule exemptions work.
  - Expired exemptions are ignored.
  - The 429 response has the correct headers.
  - `response_override` is used when set.
- Benchmark: matching cost with 100 and 500 active rules.
