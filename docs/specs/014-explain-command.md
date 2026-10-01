# SPEC-014: dynamic-throttle:explain

- Status: Draft
- ADRs: ADR-0008, ADR-0009, ADR-0004, ADR-0011, ADR-0012, ADR-0017, ADR-0020, ADR-0025
- Roadmap phase: 3

## Problem
When a client is limited, or is not, an admin needs to see why: which rules match the request and in which order, who the client counts as under each, which exemption, cap, bypass or factor applies, and what the counters say. Today that takes reading the manifest and the code. ADR-0008 names `explain` as the first answer to "which rule blocked this request?". ADR-0004's fallback uses it to show whether user rules see the user.

## Behavior
```bash
php artisan dynamic-throttle:explain GET /api/orders --ip=203.0.113.7 --user=42 --header="X-Api-Key: abc"
```

The command builds the request it describes, and runs the middleware's decision on it without counting it:
1. **Whether the request is let through untouched**, and by what: the kill switch, the package's own routes, `except`, or bypass (SPEC-013).
2. **The matched rules, in evaluation order** (priority, then id). For each:
   - its mode, its limit under the scale factor, its window and its failure policy;
   - who the client counts as: the scope, and the identity as given (the IP, the user's class and id, the header's name) and as the hash that counters and `RateLimitWouldBlock` use (ADR-0024);
   - or why the rule does not count the request: the exemption that frees the client (SPEC-009), no identity (`on_missing_identity`, ADR-0022), beyond the cap (ADR-0025), or a resolver that failed, with its error;
   - the counter for that identity, and whether the next request would be allowed (open question 1).
3. **The decision:** allowed, the rule that would answer 429, or the fail-closed rule that would answer 503, as the middleware decides it (ADR-0008, SPEC-012).
4. **The route:** when the path matches one of the app's routes, its middleware in the order they run, and whether `dynamic.throttle` runs after the authentication middleware and `middleware.after` (ADR-0004, ADR-0020 point 4). When no route runs `dynamic.throttle`, it says that no rule applies to that route.

**Inputs:**
- The method and the path, as arguments. A leading slash and a query string are ignored, as the middleware ignores them.
- `--ip`: the client's IP as the app sees it, after its trusted proxies. 127.0.0.1 by default.
- `--user`: a user's id, looked up by the user provider of `--guard`, or of the default guard. The request is authenticated as that user for every guard.
- `--header="Name: value"`, repeatable: for header and `api_token` scopes (`Authorization: Bearer …`), and for custom resolvers.
- `--json`: the same explanation as JSON, for scripts. The JSON API (SPEC-017) and the dashboard's explain tool (SPEC-026) reuse it.

**It changes nothing.** It never counts a request, never writes to the database or the cache, and never compiles. It reads the manifest from the cache store, as `status` does (SPEC-013), and says when it is missing or cannot be read.

**It follows the middleware's own code.** The steps above, the matching, the planning of attempts and the identities run through the classes the middleware uses, so `explain` cannot disagree with a request. Planning moves out of `RuleEvaluator::evaluate()` into a `plan()` that both use.

## Acceptance criteria
Feature tests run on SQLite with the array cache store, and on Redis for the counters.

- **AC-1 (rules)** For a request that three rules match, it lists them in evaluation order, each with its mode, scaled limit, window and failure policy.
- **AC-2 (identities)** For each scope (`ip`, `user`, `api_token`, `header`, `global` and a custom scope), it shows the identity as given and its hash, and the hash equals the one the middleware counts under.
- **AC-3 (rules that do not count)** It shows an exempt rule with its exemption, a skipped rule without an identity, an ignored rule beyond the cap, and a rule whose resolver throws, with the error.
- **AC-4 (untouched)** It says the request goes through untouched, and why, for the kill switch, the package's own routes, an `except` path, and bypass.
- **AC-5 (scale)** With the factor 2, it shows each rule's scaled limit, and the factor with its end time.
- **AC-6 (counters)** Pending open question 1.
- **AC-7 (decision)** It gives the middleware's decision for an allowed request, a request a rule would reject, and a request a fail-closed rule would answer 503.
- **AC-8 (route)** For a route in a group with `auth`, it lists the route's middleware in running order, with `dynamic.throttle` after `auth`. For a route that does not run `dynamic.throttle`, it says no rule applies there.
- **AC-9 (read-only)** After `explain`, every counter is unchanged, no query wrote to the database, and the cache holds what it held.
- **AC-10 (JSON)** `--json` prints one JSON object with the same content, and nothing else.
- **AC-11 (inputs)** An unknown method, a user that does not exist, an IP that is not one, and a header without a colon print why, and exit 1.
- **AC-12 (manifest)** With the manifest missing, or not readable, it says so and exits 1.

## Interfaces and files
- `src/Console/ExplainCommand.php` (new): the command and its output.
- `src/Support/Explainer.php` (new, internal): builds the explanation, as readonly value objects that render as a table or as JSON.
- `src/Engine/Stacking/RuleEvaluator.php`: `plan()`, the planning step, without the store. `evaluate()` calls it.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: the steps that let a request through untouched move to a class `explain` uses too.
- Open question 1 may add an internal read-only counter interface to both drivers.

## Out of scope
- The JSON API's endpoint (SPEC-017) and the dashboard's explain tool (SPEC-026), which reuse the explanation.
- Explaining past requests, from logs or metrics (SPEC-015).
- Running the app's real middleware stack, such as its authentication, on the simulated request.

## Edge cases and failure modes
- **A custom resolver that needs more than the request,** such as a tenant from the route or the session: it sees the simulated request only, and its error is shown, as AC-3 says.
- **The counter store is down:** the plan and the decision without counters are still shown, and the store's error.
- **A custom `LimiterStore`:** see open question 1.
- **The user provider** queries the database: `explain` is a command, not the request path, so it may.

## End-to-end verification
- `composer test -- --filter=SPEC-014`, `composer test:drivers` for the counters on Redis, `composer analyse`, `format:check` and `refactor:check`
- `composer test:mutate`, since `src/Engine` changes (`RuleEvaluator::plan()`)
- `composer bench`: `MiddlewareBench`, since the middleware's steps move
- the review subagent: the engine and the public API (the command)

## Open questions
1. **Does `explain` read the counters, to say whether the next request would be limited?** The store contract, `LimiterStore`, is public API (ADR-0017) and has no read-only call: `attempt()` counts, and `release()` uncounts. Options:
   - **(Recommended) Yes, through a new internal read-only interface** that the cache and Redis drivers implement. They read the current and the previous window, as their counting does, and compute the estimate with the same `SlidingWindowCounter`. A custom `LimiterStore` shows the plan without counters. The public contract does not change.
   - No counters: `explain` shows the plan and the limits, but not whether a client is over them. Smaller, but it cannot answer "why is this user getting 429s?".
   - Add `peek()` to `LimiterStore` itself: every store, custom ones too, would have to implement it. A breaking change to a public contract before 1.0.

## Decisions
Recorded as recommended (ADR-0027). The maintainer can veto them in the PR.
1. **The request is given as a method and a path, with `--ip`, `--user`, `--guard` and `--header`.** These are what the scopes read (ADR-0009).
2. **The user is looked up by the guard's user provider**, and the simulated request is authenticated as that user for every guard, since no authentication middleware runs.
3. **Identities show as given and as their hash.** The CLI is for admins, and the hash ties the output to counters and to `RateLimitWouldBlock`.
4. **The route's middleware is shown in running order**, which answers ADR-0004's question: whether user rules see the user.
5. **`--json` prints the same explanation**, which SPEC-017 and SPEC-026 reuse.
