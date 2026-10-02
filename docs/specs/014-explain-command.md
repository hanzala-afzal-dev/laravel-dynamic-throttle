# SPEC-014: dynamic-throttle:explain

- Status: In progress
- ADRs: ADR-0008, ADR-0009, ADR-0004, ADR-0011, ADR-0012, ADR-0017, ADR-0020, ADR-0025
- Roadmap phase: 3

## Problem
When a client is limited, or is not, an admin needs to see why: which rules match the request and in which order, who the client counts as under each, which exemption, cap, bypass or factor applies, and what the counters say. Today that takes reading the manifest and the code. ADR-0008 names `explain` as the first answer to "which rule blocked this request?". ADR-0004's fallback uses it to show whether user rules see the user.

## Behavior
```bash
php artisan dynamic-throttle:explain GET /api/orders --ip=203.0.113.7 --user=42 --header="X-Api-Key: abc"
```

The command builds the request it describes, and runs the middleware's decision on it without counting it:
1. **Whether the request is let through untouched**, and by what: a route that does not run `dynamic.throttle`, the kill switch, the package's own routes, `except`, bypass (SPEC-013), or no matched rule.
2. **The matched rules, in evaluation order** (priority, then id). For each:
   - its pattern, its mode, its limit under the scale factor, its window and its failure policy;
   - who the client counts as: the scope, and the identity as given, which is the string that is hashed, such as `ip:203.0.113.7` or `user:App\Models\User:42`, and as the hash that counters and `RateLimitWouldBlock` use (ADR-0024);
   - or why the rule does not count the request: the exemption that frees the client (SPEC-009), no identity (`on_missing_identity`, ADR-0022), beyond the cap (ADR-0025), a resolver that failed, with its error, or an earlier fail-closed rule that stopped evaluation;
   - the counter for that identity (decision 1): the count in the sliding window, rounded up; what remains, which is how many more requests the client can make now; and whether the next request would be allowed, with its `Retry-After` when not. A custom `LimiterStore` shows no counters.
3. **The decision,** as the evaluator's decision step makes it from the counters it read (decision 8): allowed, with the rate-limit headers the next request would get; the rule that would answer 429, with its headers; or the fail-closed rule that would answer 503 (ADR-0008, SPEC-012).
4. **The route:** when the path matches one of the app's routes, its middleware in the order they run, and whether `dynamic.throttle` runs after the authentication middleware and `middleware.after` (ADR-0004, ADR-0020 point 4). When no route runs `dynamic.throttle`, it says that no rule applies there, unless the app runs it as global middleware.

**Inputs:**
- The method and the path, as arguments. A leading slash and a query string are ignored, as the middleware ignores them.
- `--ip`: the client's IP as the app sees it, after its trusted proxies. 127.0.0.1 by default.
- `--user`: a user's id, looked up by the user provider of `--guard`, or of the default guard. The request is authenticated as that user on that guard, which becomes the default guard, as Laravel's `auth` middleware leaves it (decision 3).
- `--header="Name: value"`, repeatable: for header and `api_token` scopes (`Authorization: Bearer …`), and for custom resolvers.
- `--json`: the same explanation as JSON, for scripts. The JSON API (SPEC-017) and the dashboard's explain tool (SPEC-026) reuse it.

**It changes nothing.** It never counts a request, never writes to the database or the cache, and never compiles. It reads the manifest from the cache store, as `status` does (SPEC-013), and says when it is missing or cannot be read.

**It follows the middleware's own code.** The steps above, the matching, the planning of attempts, the identities and the decision run through the classes the middleware uses, so `explain` cannot disagree with a request. Planning and deciding move out of `RuleEvaluator::evaluate()` into `plan()` and `decide()`, which both use.

**The JSON** is one object:
- `request`: `method`, `path`, `ip`, `user` (the id given, or null), `guard`, and `headers` (the names given, without their values).
- `manifest`: its `version`, and how many `rules` and `exemptions` it holds.
- `settings`: `bypass` and `scale`, each null when off, else its `until` (ISO 8601 in UTC, or null for no end time), and the scale's `factor`.
- `route`: null when no route matches, else its `uri`, `name`, `middleware` in running order, whether it is `throttled`, and whether that runs `after_auth`.
- `rules`, in evaluation order, each with `id`, `name`, `method`, `pattern`, `priority`, `mode`, `limit` (scaled), `max_attempts`, `window_seconds`, `on_failure`, `scope`, `scope_parameter`, and:
  - `status`: `counts`, `exempt`, `skipped`, `ignored`, `failed` or `not_reached`;
  - `identity` and `hash`, `exemption`, and `error`, each null when it does not apply;
  - `counter`: null, or the `count`, the `remaining`, the `next` request's outcome (`allowed` or `limited`), and its `retry_after` (null when allowed).
- `counters`: whether they were `read`, and the `error` that kept them unread.
- `decision`: its `outcome` (`allowed`, `rejected`, `failed`, `unavailable`, `untouched` or `unknown`), the `reason` it is untouched (`not_attached`, `disabled`, `own_route`, `except`, `bypass` or `no_rule`), the deciding `rule`, and the rate-limit `headers` of the next request.

## Acceptance criteria
Feature tests run on SQLite with the array cache store, and on Redis for the counters. The clock is frozen.

- **AC-1 (rules)** For a request that three rules match, it lists them in evaluation order, each with its mode, scaled limit, window and failure policy.
- **AC-2 (identities)** For each scope (`ip`, `user`, `api_token`, `header`, `global` and a custom scope), it shows the identity as given and its hash, and the hash equals the one the middleware counts under.
- **AC-3 (rules that do not count)** It shows an exempt rule with its exemption, a skipped rule without an identity, an ignored rule beyond the cap, a rule whose resolver throws, with the error, and the rules after a fail-closed rule that stopped evaluation.
- **AC-4 (untouched)** It says the request goes through untouched, and why, for the kill switch, the package's own routes, an `except` path, bypass, and a path no rule matches.
- **AC-5 (scale)** With the factor 2, it shows each rule's scaled limit, and the factor with its end time.
- **AC-6 (counters)** With a rule of limit 5, after three counted requests from the client, it shows 3 counted, 2 remaining and the next request allowed, and gives the headers of the request that follows: `X-RateLimit-Remaining` 1. After five, it shows 5 counted, 0 remaining, the next request limited, and its `Retry-After`, and the request that follows gets the 429 with the headers it gave. The same holds on the cache driver and on Redis. With a custom `LimiterStore`, it shows the plan, says it cannot read the counters, and gives no decision while an enforcing rule counts.
- **AC-7 (decision)** It gives the middleware's decision for an allowed request, a request a rule would reject, a request a fail-closed rule would answer 503, and a request on a counter store that fails.
- **AC-8 (route)** For a route in a group with `auth`, it lists the route's middleware in running order, with `dynamic.throttle` after `auth`. For a route that does not run `dynamic.throttle`, and for a path no route matches, it says no rule applies there. With the middleware global, every path runs it.
- **AC-9 (read-only)** After `explain`, every counter is unchanged, no query wrote to the database, and the cache holds what it held.
- **AC-10 (JSON)** `--json` prints one JSON object with the same content, and nothing else. An error prints an object with the `error`, and nothing else.
- **AC-11 (inputs)** An unknown method, a user that does not exist, a guard that does not exist, `--guard` without `--user`, an IP that is not one, and a header without a colon print why, and exit 1. The method's case, a leading slash and a query string do not matter.
- **AC-12 (manifest)** With the manifest missing, or not readable, it says so and exits 1.
- **AC-13 (peek)** On every store of the contract suite, `peek()` gives the count, and the attempt that the next `attempt()` makes, and changes no counter.

## Interfaces and files
- `src/Console/ExplainCommand.php` (new): the command and its output.
- `src/Support/Explainer.php` (new, internal): builds the explanation, as readonly value objects that render as text or as JSON.
- `src/Engine/Stacking/RuleEvaluator.php`: `plan()`, the planning step, and `decide()`, the deciding step, both without the store. `evaluate()` calls them, and counts in between.
- `src/Engine/Stacking/Plan.php` (new): what `plan()` returns: the rules that count the request and their attempts, and the rules that do not, with why.
- `src/Http/Untouched.php` (new, internal): the steps that let a request through untouched, which the middleware and `explain` share.
- `src/Http/RequestIdentityResolver.php`: `identity()`, the identity as given, which `resolve()` hashes.
- `src/Engine/Contracts/CounterReader.php` (new, internal): `peek(CounterKey $key, int $maxAttempts, int $windowSeconds): CounterReading`. The cache and Redis drivers implement it, from the current and the previous window and the same `SlidingWindowCounter`. On Redis, one script reads both windows and `TIME`, and changes nothing.
- `src/Engine/CounterReading.php` (new): the count, and the `Attempt` the next request would make (decision 7).

## Out of scope
- The JSON API's endpoint (SPEC-017) and the dashboard's explain tool (SPEC-026), which reuse the explanation.
- Explaining past requests, from logs or metrics (SPEC-015).
- Running the app's real middleware stack, such as its authentication, on the simulated request.
- A server's cooldown (SPEC-012), which lives in each server's memory: `explain` decides as a server out of its cooldown does.
- Strict mode's rethrow (SPEC-012 decision 4): `explain` shows a resolver's error, not that strict mode would turn it into an exception.

## Edge cases and failure modes
- **A custom resolver that needs more than the request,** such as a tenant from the session: it sees the simulated request only, and its error is shown, as AC-3 says. The matched route is bound to the request, so a resolver that reads route parameters sees them.
- **The counter store is down:** the plan is shown, the store's error, and the decision a request gets from a store that fails: the first fail-closed rule answers 503, and otherwise the request goes through (SPEC-012).
- **A custom `LimiterStore`** that does not implement `CounterReader`: `explain` shows the plan, and that it cannot read the counters. The decision is `unknown` while an enforcing rule counts the request, since its counter decides.
- **Counters move meanwhile:** other requests can count between `explain` and the next request. `explain` reads a moment's state, as a request would.
- **The user provider** queries the database: `explain` is a command, not the request path, so it may.
- **The routes:** the command loads the app's middleware groups, aliases and priority list through the HTTP kernel, as a web request does, so the route's middleware are sorted as they run.

## End-to-end verification
- `composer test -- --filter=SPEC-014`, `composer test:drivers` for the counters on Redis and the contract suite, `composer analyse`, `format:check` and `refactor:check`
- `composer test:mutate`, since `src/Engine` changes (`RuleEvaluator::plan()` and `decide()`, `peek()` in the drivers)
- `composer bench`: `MiddlewareBench`, since the middleware's steps move
- the review subagent: the engine and the public API (the command)

## Open questions
None left. The maintainer answered the one there was on 2026-10-01: decision 1.

## Decisions
Recorded as recommended (ADR-0027), except the first, which the maintainer decided on 2026-10-01. The maintainer can veto the others in the PR.
1. **`explain` reads the counters through an internal read-only interface** (decided by the maintainer). The cache and Redis drivers implement `CounterReader`; a custom `LimiterStore` shows the plan without counters. The public `LimiterStore` contract does not change.
   - Rejected: no counters, which cannot answer "why is this user getting 429s?"; and `peek()` on `LimiterStore`, a breaking change to a public contract.
2. **The request is given as a method and a path, with `--ip`, `--user`, `--guard` and `--header`.** These are what the scopes read (ADR-0009).
3. **The user is looked up by the user provider of `--guard`, or of the default guard, and the request is authenticated as that user on that guard, which becomes the default,** as Laravel's `auth` middleware leaves it. Revised while writing the tests: the draft authenticated every guard, which would count an admin guard's client as the default provider's user.
4. **Identities show as given and as their hash.** The CLI is for admins, and the hash ties the output to counters and to `RateLimitWouldBlock`. Header values are not echoed back in `request`: the identity shows the one a scope hashes.
5. **The route's middleware is shown in running order**, which answers ADR-0004's question: whether user rules see the user. A route that does not run `dynamic.throttle` leaves the request untouched, so the decision says so.
6. **`--json` prints the same explanation**, which SPEC-017 and SPEC-026 reuse. Times are ISO 8601 in UTC. An error prints `{"error": …}`, so a script always gets JSON.
7. **`peek()` returns the count and the `Attempt` the next request would make,** so `explain` shows exactly what that request would get: whether it is allowed, what remains after it, and its `Retry-After`. Revised while writing the tests: an `Attempt` alone has no count once the limit is reached.
8. **The decision comes from the evaluator's own deciding step,** fed with the attempts the counters say the next request would make. A failed read is fed to it as a failed store call, so a store that is down gives the failure policy's decision.
