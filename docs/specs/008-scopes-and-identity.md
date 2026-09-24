# SPEC-008: Scopes, identities and custom resolvers

- Status: Draft
- ADRs: ADR-0004, ADR-0009, ADR-0010, ADR-0017, ADR-0020, ADR-0022
- Roadmap phase: 2

## Problem
A rule counts requests per client, and its scope decides who the client is (ADR-0009): an IP address, a user, an API token, a header value, everyone, or something the app defines, such as a tenant or a plan. SPEC-007's evaluator asks an `IdentityResolver` for each rule's identity, but nothing implements it yet. An identity must be hard to dodge, for example by rotating IPv6 addresses. It must never put a raw identifier in a cache key, and resolving it must not add database queries to the request path.

## Behavior
- **Identities per scope** (ADR-0009, ADR-0022):

  | Scope | Identity | Missing when |
  |---|---|---|
  | `ip` | The client IP, IPv6 grouped by prefix | never |
  | `user` | The authenticated user: its class and `getAuthIdentifier()` | the request has no authenticated user |
  | `api_token` | The bearer token | there is no bearer token, or it is empty |
  | `header` | The value of the header that `scope_parameter` names | the header is absent or empty |
  | `global` | One identity for every request | never |
  | `custom` | What the resolver registered as `scope_parameter` returns | the resolver returns `null` or `''` |

- **A missing identity** follows the rule's `on_missing_identity` (ADR-0022):
  - `ip`, the default: the request counts under the scope's IP fallback: `guest:<ip>` for `user`, `anon:<ip>` for `api_token`, and `missing:<ip>` for `header` and `custom` (ADR-0009). The IP is grouped as in the `ip` scope, so rotating IPv6 addresses cannot escape a fallback either.
  - `skip`: the rule does not apply to the request. The resolver returns `null`, and the evaluator skips the rule (SPEC-007).
  - A present identity and a fallback never share a counter, because they hash different prefixes: `user:…` and `guest:…`, for example.
- **The client IP** comes from `$request->ip()`, so the app's trusted proxies decide it (ADR-0009):
  - An IPv4 address is used whole.
  - An IPv6 address is grouped by `scopes.ip.ipv6_prefix`, default 64: `2001:db8:1:2::1` and `2001:db8:1:2:ffff::9` share an identity.
  - An IPv4-mapped IPv6 address (`::ffff:203.0.113.7`) counts as its IPv4 address.
  - A request with no IP counts as `unknown`: all such requests share one identity.
- **Users and guards** (ADR-0020 point 5):
  - By default, `scopes.user.guards` is `null`. The user is then the one the request authenticated with: the default guard, which Laravel's auth middleware sets, and only when that guard already holds a user (`hasUser()`). The scope never triggers a user lookup of its own, so a route without auth middleware counts its requests as guests, as ADR-0004 expects.
  - A list, such as `['sanctum', 'web']`, asks each guard in order, and the first user found wins. Asking a guard that has not authenticated the request costs that guard's own lookup, such as Sanctum's token query. That is the documented price of the option (ADR-0020).
  - The identity includes the user's class, so a `User` and an `Admin` with the same id never share a counter (open question 2).
- **API tokens** come from `$request->bearerToken()`. Only the hash is ever stored.
- **Custom scopes** are registered by the app, usually in a service provider's `boot()`:
  ```php
  use Afzal\DynamicThrottle\Facades\DynamicThrottle;

  DynamicThrottle::scope('tenant', fn (Request $request) => tenant()?->getTenantKey());
  DynamicThrottle::scope('plan', PlanScope::class); // implements ScopeResolver
  ```
  - A resolver is a closure, a `ScopeResolver` instance, or the class name of one. A class name is resolved from the container each time a request needs it, so it never carries state from one request to the next.
  - It receives the current request and returns a string, an integer or a `Stringable`. `null` or `''` means the identity is missing.
  - Names are 1 to 64 characters from `a-z`, `0-9`, `_` and `-`, like rule ids. Registering a name again replaces the resolver.
  - Per-tenant limits are one rule with the `custom` scope `tenant` (ADR-0020 point 8): each tenant gets its own counter.
- **Resolved once per request.** Within one request, each scope and parameter is resolved at most once, however many rules use it. A custom resolver that queries the database runs once per request, not once per rule. Nothing is kept from one request to the next, on Octane either.
- **Hashing.** An identity is hashed before it reaches a key (ADR-0009), into 64 lowercase hex characters. Open question 3: HMAC-SHA-256 with a key derived from `APP_KEY`, instead of plain SHA-256.
- **Resolution errors:**
  - These count as resolution errors:
    - a `custom` scope that is not registered
    - a resolver that throws, or returns anything else, such as an array, an object or `true`
    - a guard that is not defined, or fails
  - Open question 1: the evaluator ends with a `Failed` outcome for that rule, as for a failed attempt (SPEC-007). The error is an `IdentityUnavailableException` that names the scope and keeps the cause.
  - SPEC-012 then applies the rule's failure policy, reporting and strict mode (ADR-0020 point 1).
- **Location** (open question 4):
  - The pure parts live in `src/Engine/Identity`, so the mutation gate covers them: IP grouping, the identity for a scope and a value, and hashing.
  - Reading the request, the guards and the registered resolvers lives in `src/Http`.

## Acceptance criteria
Unless stated otherwise, resolver criteria use `RequestIdentityResolver` on a `Request::create()` request, with one rule of the scope under test and `on_missing_identity: ip`. "Same identity" means `resolve()` returns the same hash for both requests.

### Scopes
- **AC-1 `ip`:**
  - Two requests from `203.0.113.7` have the same identity.
  - `203.0.113.8` has a different one.
- **AC-2 IPv6:**
  - With the default prefix 64, `2001:db8:1:2::1` and `2001:db8:1:2:ffff::9` have the same identity, and `2001:db8:1:3::1` a different one.
  - With prefix 128, the first two differ.
  - With prefix 48, `2001:db8:1:2::1` and `2001:db8:1:3::1` are the same.
  - `::ffff:203.0.113.7` is the same as `203.0.113.7`.
  - Two requests without an IP are the same.
- **AC-3 `user`:**
  - User 42 from two IPs is the same identity.
  - Users 42 and 43 differ.
  - A `User` and an `Admin` model, both with id 1, differ.
- **AC-4 Guests on a `user` rule:**
  - With `ip`, two guests from `203.0.113.7` are the same identity. A guest from `203.0.113.8` differs, and neither equals user 42's identity.
  - With `skip`, `resolve()` returns `null`.
- **AC-5 Default guards** (ADR-0020 point 5):
  - The session holds user 42 on a route without auth middleware, so the guard has not resolved a user. The request counts as a guest, and no database query runs.
  - Once the guard has resolved user 42, as auth middleware does, it counts as user 42.
- **AC-6 A guard list:**
  - With `['api', 'web']`, a request whose user is only on `web` counts as that user.
  - A guard name that is not defined is a resolution error (AC-12).
- **AC-7 `api_token`:**
  - `Bearer abc` from two IPs is the same identity.
  - `abc` and `abd` differ.
  - No token, or an empty `Bearer `, is missing: it gives the `anon:<ip>` identity with `ip`, and `null` with `skip`.
- **AC-8 `header`** (`X-Client-Id`):
  - `c1` twice is the same identity, and `c1` and `c2` differ.
  - The header name matches case-insensitively.
  - An absent or empty header is missing: it gives `missing:<ip>` with `ip`, and `null` with `skip`.
- **AC-9 `global`:** requests from two IPs and two users all have the same identity.
- **AC-10 `custom`** (`plan`):
  - `gold` and `silver` differ.
  - `7` and `'7'` are the same, and a `Stringable` is the same as its string.
  - `null` and `''` are missing.
  - The resolver receives the request being resolved.
- **AC-11 Registration:**
  - A `ScopeResolver` class name is resolved from the container.
  - Registering a name again replaces the resolver.
  - `Plan`, `''` and a 65-character name are rejected at registration with an `InvalidArgumentException`.

### Errors, memoization and hashing
- **AC-12 Resolution errors** (open question 1). Take a rule `a` that counts, then a rule `b` whose scope fails in each of these ways:
  - `b`'s custom scope is not registered
  - its resolver throws a `RuntimeException`
  - its resolver returns `['x']`, `new stdClass` or `true`
  - `scopes.user.guards` lists an undefined guard

  In every case, `evaluate()` returns `Failed` for rule `b` with an `IdentityUnavailableException` that names the scope and keeps the cause as its previous exception. Rule `a` keeps its count, and `evaluate()` does not throw.
- **AC-13 Once per request:**
  - Three matched rules with the custom scope `plan` call the resolver once.
  - Two requests call it once each, with their own values.
- **AC-14 No raw identifier in any key:**
  - Rules of every scope are evaluated on the cache driver.
  - Afterwards, no key the store wrote contains the token `tok-QWERTY`, the header value `client-QWERTY`, the plan `plan-QWERTY` or the IP `203.0.113.7`.
  - Every identity is 64 lowercase hex characters.
- **AC-15 Keyed hashing** (open question 3): the same identity under two app keys hashes differently, and neither hash is the plain SHA-256 of the identity.

### Tenants, config and arch
- **AC-16 Per-tenant limits** (ADR-0020 point 8). One rule has the custom scope `tenant`, max 2, window 60, on the cache driver with the fake clock:
  - Tenant `acme`'s third request is rejected.
  - Tenant `globex`'s first request is still allowed.
- **AC-17 Config:**
  - `scopes.ip.ipv6_prefix` comes from `DYNAMIC_THROTTLE_IPV6_PREFIX` and defaults to 64. `0`, `129`, `wide`, `1.5` and `true` fall back to 64.
  - `scopes.user.guards` defaults to `null`.
  - A published value that is neither `null` nor a non-empty list of strings is treated as `null`.
- **AC-18 Arch:**
  - `Engine\Identity` falls under the engine's rules: no database and no wall clock.
  - The facade and `Http` touch no database.

## Interfaces and files
- `src/Contracts/ScopeResolver.php`: `resolve(Request $request): string|int|Stringable|null`. This is the public scope-resolver contract (ADR-0017).
- `src/Facades/DynamicThrottle.php`, backed by `src/DynamicThrottle.php`: `scope(string $name, Closure|ScopeResolver|string $resolver): void`.
  - The facade is public API (ADR-0017). It registers no global alias.
  - `fake()` and `strict()` come later (SPEC-010, SPEC-012).
- `src/Engine/Identity/ClientIp.php`: groups an IP.
- `src/Engine/Identity/ScopeIdentity.php`: the identity for a rule, from the scope's value and the grouped IP, or `null` to skip.
- `src/Engine/Identity/IdentityHasher.php`: hashes an identity.
- `src/Engine/Exceptions/IdentityUnavailableException.php`. With open question 1, `RuleEvaluator` catches it and `RuleFailure` accepts it.
- `src/Http/RequestIdentityResolver.php`: implements `Engine\Contracts\IdentityResolver` for one request. `forRequest(Request)` is what SPEC-010's middleware calls.
- `config/dynamic-throttle.php`: the `scopes` section, with `ip.ipv6_prefix` and `user.guards`.
- Tests:
  - `tests/Feature/Http/RequestIdentityResolverTest.php`: AC-1 to AC-13
  - `tests/Unit/Engine/Identity/`: the pure parts
  - `tests/Feature/Http/IdentityKeysTest.php`: AC-14
  - `tests/Unit/Engine/Identity/IdentityHasherTest.php`: AC-15
  - `tests/Feature/Http/TenantScopeTest.php`: AC-16
  - `tests/Unit/ConfigTest.php`: AC-17
  - `tests/Arch/ArchTest.php`: AC-18

## Out of scope
- Exemptions (SPEC-009). They will also make a rule not apply.
- The middleware that builds the resolver for each request (SPEC-010).
- What a resolution error does to the response: the failure policy, `report()` and strict mode (SPEC-012).
- The health check's warnings for a dominant IP and for user rules that see mostly guests, and the dashboard's warning on header scopes (SPEC-016, Phase 3).

## Edge cases and failure modes
- **Proxies.** Behind a load balancer without trusted proxies, every request has the balancer's IP. That is the app's configuration (ADR-0009), and the health check makes it visible (SPEC-016).
- **Header scopes can be spoofed.** A client that rotates the header value gets a new counter each time (ADR-0009). Use them only for headers a trusted gateway sets.
- **A route without auth middleware** counts logged-in users as guests by default (AC-5). The fix is to attach the rule after auth, or to list guards and pay their lookup.
- **Octane.** Registered resolvers live for the worker, because they are registered at boot. Resolved identities live for one request.
- **Large values.** A 10 KB header value is hashed like any other.
- **An identifier that is an object,** such as a UUID value object from `getAuthIdentifier()`, counts by its string form when it is `Stringable`, and is a resolution error otherwise.
- **Rotating `APP_KEY`** would reset every counter once, with open question 3. Counter windows are at most 24 hours.
- **No `APP_KEY`:** identities fall back to plain SHA-256, and the health check says so (SPEC-016), so rate limiting keeps working.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`
- `composer test:mutate`: `Engine\Identity` is in the gate

## Open questions
1. **A resolution error ends evaluation with a `Failed` outcome for that rule**, as a failed attempt does. This came from the SPEC-007 review. Today such an error escapes `evaluate()`, and the rule that failed, plus what earlier rules counted, is lost. ADR-0020 point 1 needs both to apply that rule's failure policy, and strict mode needs the error.
   - *Recommended:* yes, through `IdentityUnavailableException`, which wraps the cause.
2. **The user identity includes the user's class.** ADR-0009 names only `getAuthIdentifier()`, so a `User` and an `Admin` with the same id would share a counter in apps with more than one user model.
   - *Recommended:* include the class. Renaming a user model then resets its counters once.
3. **Keyed hashing.** Plain SHA-256 hides tokens, but not small spaces. Every IPv4 address, or every numeric user id, can be hashed and matched in seconds, so anyone who can read the cache can recover them. That defeats ADR-0009's point. HMAC-SHA-256 with a key derived from `APP_KEY` costs the same.
   - *Recommended:* yes, recorded in a short ADR that amends ADR-0009.
4. **Where the code lives.**
   - *Recommended:* the pure identity code in `src/Engine/Identity`, so mutation testing covers it, and the request-reading code in `src/Http`.
5. **The cap and `skip`,** raised in PR #4 and not yet answered. `skip` arrives with this spec, and skipped rules still use cap slots. Five `user` rules with `skip` can push a guest rule past the cap, and then guests are never limited.
   - *Recommended:* a short ADR amending ADR-0008, in its own small PR right after this one and before SPEC-010 applies rules to requests. Skipped rules would never use a slot, and dry-run rules would get their own.
