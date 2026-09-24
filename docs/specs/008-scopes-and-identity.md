# SPEC-008: Scopes, identities and custom resolvers

- Status: In progress (follow-ups: decisions 6 to 8)
- ADRs: ADR-0004, ADR-0009, ADR-0010, ADR-0017, ADR-0020, ADR-0022, ADR-0024, ADR-0025
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
  - So does an address in the well-known NAT64 prefix `64:ff9b::/96` (RFC 6052), such as `64:ff9b::203.0.113.7` (decision 8). A translator in front of an IPv6-only server presents IPv4 clients that way, and grouping them by prefix would put every IPv4 client in one counter.
  - A request with no IP counts as `unknown`: all such requests share one identity.
  - The IP is read only when a rule needs it: the `ip` scope, or a fallback. A `global` rule, or a present identity, still resolves when the IP cannot be read.
- **Users and guards** (ADR-0020 point 5):
  - By default, `scopes.user.guards` is `null`. The user is then the one the request authenticated with: the default guard, which Laravel's auth middleware sets, and only when that guard already holds a user (`hasUser()`). The scope never triggers a user lookup of its own, so a route without auth middleware counts its requests as guests, as ADR-0004 expects.
  - A list, such as `['sanctum', 'web']`, asks each guard in order, and the first user found wins. Asking a guard that has not authenticated the request costs that guard's own lookup, such as Sanctum's token query. That is the documented price of the option (ADR-0020).
  - The identity includes the user's class, so a `User` and an `Admin` with the same id never share a counter.
- **API tokens** come from `$request->bearerToken()`, without the spaces or tabs around them. RFC 9110 allows several spaces after `Bearer`, and Passport and jwt-auth accept `Bearer  abc` as the token `abc`. Without trimming, one token could multiply its limit. Only the hash is ever stored.
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
  - **Multi-database tenancy** (decision 7). Counters live outside the tenancy bootstrappers (ADR-0020 point 8), so on a `user` rule, user 42 of one tenant and user 42 of another are one client. The package does not detect tenancy packages. Instead, a custom scope that adds the tenant key counts them apart, used in place of `user` on those rules:
    ```php
    use Illuminate\Support\Facades\Auth;

    DynamicThrottle::scope('tenant-user', function (): ?string {
        $guard = Auth::guard();

        // Like the user scope: only a user that auth has already loaded, so no lookup of its own.
        if (tenant() === null || ! $guard->hasUser()) {
            return null;
        }

        $user = $guard->user();

        // json_encode keeps the parts apart, whatever characters they contain.
        return json_encode([tenant()->getTenantKey(), $user::class, $user->getAuthIdentifier()], JSON_THROW_ON_ERROR);
    });
    ```
    `tenant()` is stancl/tenancy's helper. With spatie/laravel-multitenancy, use `Tenant::current()` and its `getKey()`.
- **Resolved once per request.** Within one request, each scope and parameter is resolved at most once, however many rules use it. A custom resolver that queries the database runs once per request, not once per rule. Nothing is kept from one request to the next, on Octane either.
  - A scope that fails is not asked again (decision 6). Every rule that uses it fails with the same exception, so a failing resolver or guard also runs once per request.
  - After a failed read, the client IP is read again for each rule that needs it. The read costs nothing, and each error names its own rule's scope.
- **Hashing.** An identity is hashed before it reaches a key, into 64 lowercase hex characters: HMAC-SHA-256 with a key derived from `APP_KEY` (ADR-0024, which amends ADR-0009). Plain SHA-256 would hide tokens, but not IPv4 addresses or numeric ids, which can be hashed and matched in seconds.
- **Resolution errors:**
  - These count as resolution errors:
    - a `custom` scope that is not registered
    - a resolver that throws, or returns anything else, such as an array, an object or `true`
    - a guard that is not defined, or fails
    - a user id, or a custom value, that cannot be read or turned into a string
    - a client IP that cannot be read, such as when trusted proxies send conflicting `Forwarded` and `X-Forwarded-For` headers
  - The error is an `IdentityUnavailableException` that names the scope and keeps the cause.
  - It fails only its own rule (decision 6). The evaluator lists the failure and goes on to the next rule, so a client that makes one scope fail is still counted by the others, such as an IP limit after a custom scope.
  - The failed rule makes no attempt, so it takes no slot (ADR-0025).
  - Evaluation then ends as usual:
    - An enforcing rule that rejects the request later still rejects it: `Rejected`, with the failures listed.
    - A failed attempt later stops evaluation, because the later rules would fail too (SPEC-007): `Failed`, with every failure listed in order.
    - Otherwise: `Failed`, and every other rule that applied has counted the request.
  - SPEC-012 then applies each failed rule's failure policy, reporting and strict mode (ADR-0020 point 1). It also decides whether a fail-closed rule's 503 or a later rule's 429 wins, and whether a 503 releases what the other rules counted.
- **Location:**
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
  - `64:ff9b::203.0.113.7` is the same as `203.0.113.7`, whatever the prefix. `64:ff9b::1:cb00:7107`, which is outside the /96, and the local-use `64:ff9b:1::cb00:7107` (RFC 8215) are grouped by prefix like any IPv6 address.
  - Two requests without an IP are the same.
- **AC-3 `user`:**
  - User 42 from two IPs is the same identity.
  - Users 42 and 43 differ.
  - A `User` and an `Admin` model, both with id 1, differ.
- **AC-4 Guests on a `user` rule:**
  - With `ip`, two guests from `203.0.113.7` are the same identity. A guest from `203.0.113.8` differs, and neither equals user 42's identity.
  - With `skip`, `resolve()` returns `null`.
- **AC-5 Default guards** (ADR-0020 point 5):
  - The session holds user 42 on a route without auth middleware, with a session guard and a database user provider. The guard has not loaded the user, so the request counts as a guest, and no database query runs.
  - Once the guard has loaded user 42, as auth middleware does with one query, the request counts as user 42.
- **AC-6 A guard list:**
  - With `['none', 'api', 'web']`, where `none` has no user and `api` and `web` have users 1 and 2, the request counts as user 1, and `web` is never asked.
  - A guard name that is not defined is a resolution error (AC-12).
- **AC-7 `api_token`:**
  - `Bearer abc` from two IPs is the same identity.
  - `abc` and `abd` differ.
  - `Bearer  abc` (two spaces), `Bearer` and a tab, `Bearer abc ` and `Bearer abc ,x` are the same identity as `Bearer abc`.
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
  - `Plan`, `''` and a 65-character name are rejected at registration with an `InvalidArgumentException`, and so is a class that is not a `ScopeResolver`.

### Errors, memoization and hashing
- **AC-12 Resolution errors.** Take a `global` rule `a`, then a rule `b` whose scope fails in each of these ways, then a `global` rule `c`:
  - `b`'s custom scope is not registered
  - its resolver throws a `RuntimeException`
  - its resolver returns `['x']`, `new stdClass` or `true`, or a `Stringable` whose `__toString()` throws
  - `scopes.user.guards` lists an undefined guard
  - the user's `getAuthIdentifier()` throws, or returns a `Stringable` whose `__toString()` throws
  - `b` is an `ip` rule, and trusted proxies send conflicting `Forwarded` and `X-Forwarded-For` headers

  In every case, `evaluate()` lists one failure, for rule `b`: an `IdentityUnavailableException` that names the scope, and keeps the cause as its previous exception when there is one. Rules `a` and `c` both count the request, the outcome is `Failed`, and `evaluate()` does not throw. With the conflicting headers, `global` and a present user still resolve.
- **AC-19 An identity error fails only its own rule** (decision 6). Rule `b`'s identity cannot be resolved:
  - An enforcing rule after `b` that rejects the request still rejects it. The outcome is `Rejected`, the earlier rule's count is released, and `b`'s failure is listed.
  - A rule after `b` whose attempt fails stops evaluation with a `Failed` outcome. Both failures are listed, `b`'s first, and the rule after them does not count the request.
  - `b` takes no slot: with a cap of 1, the rule after `b` still counts the request.
- **AC-13 Once per request:**
  - Three matched rules with the custom scope `plan` call the resolver once.
  - Two requests call it once each, with their own values.
  - A scope that fails is asked once too. Three `custom` rules call a resolver that throws once, and three `user` rules ask a guard that throws, listed in `scopes.user.guards`, once. Each time, the three rules fail with the same exception.
- **AC-14 No raw identifier in any key:**
  - Rules of every scope are evaluated on the cache driver.
  - Afterwards, no key the store wrote contains the token `tok-QWERTY`, the header value `client-QWERTY`, the plan `plan-QWERTY` or the IP `203.0.113.7`.
  - Every identity is 64 lowercase hex characters.
- **AC-15 Keyed hashing** (ADR-0024):
  - The same identity under two app keys hashes differently, and neither hash is the plain SHA-256 of the identity.
  - Without an app key, the hash is the plain SHA-256.
  - A known identity under a known key always hashes to the same value, because a changed hash would reset every counter.

### Tenants, config and arch
- **AC-16 Per-tenant limits** (ADR-0020 point 8). One rule has the custom scope `tenant`, max 2, window 60, on the cache driver with the fake clock:
  - Tenant `acme`'s third request is rejected.
  - Tenant `globex`'s first request is still allowed.
  - The multi-database recipe's scope, with max 1, counts user 42 of `acme` and user 42 of `globex` apart: in `acme`, user 42's second request is rejected, and in `globex`, user 42's first is still allowed. A guest is missing.
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
- `src/Engine/Exceptions/IdentityUnavailableException.php`. `RuleEvaluator` catches it, and `RuleFailure` accepts it as well as a `StorageUnavailableException`.
- `src/Engine/Stacking/Evaluation.php`: `$failures` lists every failed rule in order, and replaces `$failure` (decision 6).
- `src/Http/RequestIdentityResolver.php`: implements `Engine\Contracts\IdentityResolver` for one request. The container builds it for a request, `make(RequestIdentityResolver::class, ['request' => $request])`, as SPEC-010's middleware will.
- `src/Http/IdentitySettings.php`: the `scopes` section, checked, as the provider binds it.
- `config/dynamic-throttle.php`: the `scopes` section, with `ip.ipv6_prefix` and `user.guards`.
- Tests:
  - `tests/Feature/Http/RequestIdentityResolverTest.php`: AC-1 to AC-13
  - `tests/Unit/Engine/Identity/ClientIpTest.php` and `ScopeIdentityTest.php`: the pure parts of AC-1 to AC-10
  - `tests/Unit/Engine/Stacking/RuleEvaluatorTest.php`: AC-12 and AC-19 in the evaluator
  - `tests/Feature/Http/IdentityKeysTest.php`: AC-14
  - `tests/Unit/Engine/Identity/IdentityHasherTest.php`: AC-14 and AC-15
  - `tests/Feature/Http/TenantScopeTest.php`: AC-16, with the multi-database recipe
  - `tests/Unit/ConfigTest.php` and `tests/Feature/ServiceProviderTest.php`: AC-17, and the one registry behind the facade
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
- **Rotating `APP_KEY`** resets every counter once (ADR-0024). Counter windows are at most 24 hours.
- **No `APP_KEY`:** identities fall back to plain SHA-256, and the health check says so (SPEC-016), so rate limiting keeps working.
- **A resolution error a client can cause.** For example, a custom resolver that throws on an unknown header value. Only the rules that use that scope fail (decision 6). Later rules, such as an IP limit, still count the client and reject it once it goes over, so the error cannot switch limits off.
- **A fail-closed rule whose identity fails.** The rules after it still count the request, which SPEC-012 may then answer with a 503. Whether that 503 releases their counts is SPEC-012's decision.
- **Multi-database tenancy.** User 42 of two tenants is one identity on a `user` rule, because counters live outside the tenancy bootstrappers (ADR-0020 point 8). The recipe under custom scopes counts them apart (decision 7).
- **NAT64 and SIIT.** A translator that uses the well-known prefix presents IPv4 clients as `64:ff9b::203.0.113.7`, and each counts as its IPv4 address (decision 8).
  - A translator with a network-specific prefix, including the local-use `64:ff9b:1::/48` (RFC 8215), puts its IPv4 clients in /64 groups like any IPv6 client. Such a prefix can embed the address in several places (RFC 6052), and the package does not know the translator's. A config key for those prefixes can come when someone needs it.
  - Unwrapping cannot be used to dodge a limit. A client can only complete a TCP handshake from an address whose replies reach it, and replies to `64:ff9b::203.0.113.7` go to `203.0.113.7`. So a client gets only the identities of IPv4 addresses it already has.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check` and `composer refactor:check`
- `composer test:mutate`: `Engine\Identity` is in the gate

## Open questions
None. The maintainer accepted all five recommendations on 2026-09-24:
1. A resolution error ends evaluation with a `Failed` outcome for that rule, through `IdentityUnavailableException`, which wraps the cause. SPEC-007's evaluator is amended accordingly. Decision 6 replaces the part that ends evaluation.
2. The user identity includes the user's class.
3. Identities are hashed with HMAC-SHA-256 and a key derived from `APP_KEY`. ADR-0024 records it, and amends ADR-0009.
4. The pure identity code lives in `src/Engine/Identity`, and the request-reading code in `src/Http`.
5. The cap and `skip`: a short ADR amending ADR-0008 comes in its own small PR right after this one, before SPEC-010 applies rules to requests (roadmap, Phase 2).

PR #5 raised three follow-ups, and the maintainer accepted the recommendations on 2026-09-24:
6. An identity error fails only its own rule, and evaluation goes on. A client that makes one scope fail is still counted by the other rules. A failed attempt still stops evaluation, because the store is down for the later rules too. This amends decision 1 and SPEC-007.
7. Multi-database tenancy is a documented custom-scope recipe. The package does not detect tenancy packages.
8. The well-known NAT64 prefix, `64:ff9b::/96`, is unwrapped like IPv4-mapped addresses.
