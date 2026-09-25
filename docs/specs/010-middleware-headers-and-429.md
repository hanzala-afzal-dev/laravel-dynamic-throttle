# SPEC-010: Middleware, priority, headers and 429

- Status: Draft
- ADRs: ADR-0004, ADR-0006, ADR-0008, ADR-0009, ADR-0010, ADR-0012, ADR-0020, ADR-0021, ADR-0025
- Roadmap phase: 2

## Problem
The engine can match rules, stack them, resolve identities and check exemptions, but nothing applies them to a request yet. ADR-0004 and ADR-0020 decided how the middleware plugs into an app:
- a route middleware, sorted after authentication;
- nothing attached until the app opts in;
- an `except` list for other packages' routes;
- only the 429 leaves it.

This spec builds it.

## Behavior
- **The middleware** `DynamicThrottleMiddleware`, with the alias `dynamic.throttle` (ADR-0004 point 1). The app adds it to route groups or routes. No route parameters: rules decide everything (ADR-0004).
- **Nothing is attached by default** (ADR-0020 point 11):
  - The package registers no global middleware.
  - `middleware.auto_attach` (default `[]`) lists route groups, such as `['api']`, to append the middleware to at boot.
- **It runs after authentication** (ADR-0004 point 2, ADR-0020 point 4). At boot, the package places the middleware in Laravel's middleware priority list right after:
  - Laravel's authentication middleware (`AuthenticatesRequests`);
  - every middleware in `middleware.after` (default `[]`), as aliases or classes, such as a JWT package's.

  So a group that lists `dynamic.throttle` before `auth` still runs it after `auth`.
- **For each request**, in this order, the middleware stops at the first step that lets the request through untouched:
  1. **The kill switch.** With `enabled` off, it does nothing (ADR-0011 point 4).
  2. **The package's own routes.** A route whose name starts with `dynamic-throttle.`, such as the dashboard's and its assets' (SPEC-020), is never limited, whatever the rules and `except` say (ADR-0004 point 5).
  3. **`except`.** Path patterns that no rule ever applies to, checked like rule patterns on the decoded path (ADR-0020 point 3). The defaults are ADR-0020's: `horizon/*`, `telescope/*`, `pulse`, `pulse/*`, `nova/*`, `nova-api/*`, `livewire/*`, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`.
  4. **The manifest** (SPEC-006) and **matching** (SPEC-007). No matched rule: the request goes through.
  5. **Evaluation** (SPEC-007), with this request's resolver and the manifest's exemptions (SPEC-008, SPEC-009).
- **The outcome:**
  - **Rejected:** the middleware throws Laravel's `ThrottleRequestsException`, so the app's exception handler renders the 429 like Laravel's own (ADR-0008). The counts the other rules made are released (ADR-0021). Headers, from the rejecting rule:
    - `Retry-After`: the attempt's whole seconds (ADR-0006)
    - `X-RateLimit-Reset`: the Unix time the request may retry, as Laravel sends it
    - `X-RateLimit-Limit`: the rule's limit
    - `X-RateLimit-Remaining`: `0`
  - **A rejecting rule with a `response_override`** answers 429 with that JSON body and the same headers, instead of the handler's page (ADR-0008). Like Laravel's `Limit::response()`, it throws an `HttpResponseException`.
  - **Allowed:** the request goes on. Its response gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from the most restrictive enforcing rule that counted it: the lowest remaining, and the first in evaluation order among equals (ADR-0008). A request that no enforcing rule counted gets no headers, for example when every matched rule is dry-run, exempt or skipped.
  - **Dry-run rules** count, never reject and set no headers (ADR-0012). Their events and metrics are SPEC-011's.
  - **Failed** (a counter or an identity could not be used): the request goes through, and each failure is reported with `report()` (ADR-0010 point 1). SPEC-012 adds the rest of the failure policy: fail-closed rules and 503, the cooldown, the event and strict mode.
- **Only the 429 leaves the middleware** (ADR-0020 point 1). Anything else the package throws while deciding, such as an invalid config or a resolver that throws outside its scope, is reported, and the request goes through. The app's own exceptions, thrown after the middleware passed the request on, are never touched.
- **`DynamicThrottle::fake()`** is for the app's own tests (ADR-0020 point 12). It swaps the counters and the manifest's cache for in-memory ones, so tests need no Redis and share no counts. Rules come from the rules table, as usual. A test asserts a limit through the response's status and headers (open question 4).
- **Cost.** A request whose path matches no rule costs a manifest read and one index lookup. The database is never queried (ADR-0007).

## Acceptance criteria
Feature tests on real routes through Testbench, with rules saved through the model and the manifest compiled, unless stated otherwise.

### Wiring
- **AC-1** The alias `dynamic.throttle` resolves to `DynamicThrottleMiddleware`, and the package adds no global middleware.
- **AC-2** With the default config, a `*` rule of 1 per minute, and routes in `web` and `api`, no route is limited: the second request is not a 429 (ADR-0020 point 11).
- **AC-3** With `middleware.auto_attach` = `['api']`, a route in the `api` group is limited, and one in `web` is not.
- **AC-4** Priority:
  - A group that lists `dynamic.throttle` before `auth` counts an authenticated user under a `user` rule as that user, not as a guest.
  - A custom authentication middleware listed in `middleware.after`, by alias and by class, and placed after `dynamic.throttle` in the group, still runs before it.

### Skipping
- **AC-5** With `enabled` off, a limited route is never limited, sets no headers, and reads no manifest.
- **AC-6** With a `*` rule of 1 per minute:
  - `livewire/update`, `horizon/api/stats` and `up` are never limited.
  - With `livewire/*` removed from `except`, `livewire/update` is limited.
- **AC-7** A route named `dynamic-throttle.dashboard` is never limited, even with an empty `except`.

### Responses
- **AC-8** Allowed: with enforcing rules of 10 and 5 per minute on a path, the response carries `X-RateLimit-Limit: 5` and `X-RateLimit-Remaining: 4`. With two rules at the same remaining, the headers come from the first in evaluation order.
- **AC-9** Rejected:
  - The request over a limit of 2 answers 429 through `ThrottleRequestsException`, rendered by the app's handler.
  - It carries `Retry-After`, `X-RateLimit-Reset` (now plus `Retry-After`, in Unix seconds), `X-RateLimit-Limit: 2` and `X-RateLimit-Remaining: 0`.
  - A lenient rule on the same path does not count the rejected request (ADR-0021).
- **AC-10** A rejecting rule with `response_override` `{"message": "Slow down"}` answers 429 with exactly that JSON body, and the AC-9 headers.
- **AC-11** No headers:
  - on a path where the only matched rule is dry-run, even once it would block;
  - for a client exempt from every matched rule;
  - on a path no rule matches.
- **AC-12** Dry-run: a dry-run rule of 1 per minute never answers 429, and still counts (ADR-0012).
- **AC-13** `HEAD` is limited by a `GET` rule, and a `POST` rule leaves a `GET` alone (SPEC-007).
- **AC-14** A `user` rule counts two users apart, and a client exempt by IP is never limited (SPEC-008, SPEC-009).

### Failures
- **AC-15** Each of these lets the request through, and reports the error once: a counter store that throws, an invalid `driver`, a corrupt manifest, and a custom resolver that throws.
- **AC-16** An exception the route itself throws reaches the app's handler unchanged, and the middleware reports nothing.

### Test helper and cost
- **AC-17** After `DynamicThrottle::fake()`, a route over its limit answers 429 with no Redis configured, and two tests never share a count.
- **AC-18** A limited request with a warm manifest runs no database query (query-count assertion, the Phase 2 exit criterion).
- **AC-19 (arch)** `Http\Middleware` touches no database (existing rule) and reads the time only through `Clock`.
- **AC-20 (benchmark)** A middleware benchmark: a request through the middleware with 100 rules, 3 of them matched, on local Redis and on the array store. The results record, with the hardware, the p95 it adds, against the roadmap's 1 ms target.

## Interfaces and files
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: `handle()`.
- `src/Http/RateLimitHeaders.php`: the headers of an allowed and of a rejected request, from an `Evaluation`.
- `src/DynamicThrottleServiceProvider.php`: the alias, `auto_attach`, the priority, and `fake()`'s bindings.
- `src/DynamicThrottle.php` and `src/Facades/DynamicThrottle.php`: `fake()`.
- `config/dynamic-throttle.php`: `middleware.auto_attach`, `middleware.after` and `except`, with comments.
- Tests:
  - `tests/Feature/Http/MiddlewareWiringTest.php`: AC-1 to AC-4
  - `tests/Feature/Http/MiddlewareSkippingTest.php`: AC-5 to AC-7
  - `tests/Feature/Http/MiddlewareResponseTest.php`: AC-8 to AC-14
  - `tests/Feature/Http/MiddlewareFailureTest.php`: AC-15, AC-16
  - `tests/Feature/Http/FakeTest.php`: AC-17
  - `tests/Feature/Http/MiddlewareQueryTest.php`: AC-18
  - `tests/Arch/ArchTest.php`: AC-19
  - `benchmarks/Http/MiddlewareBench.php`: AC-20

## Out of scope
- Dry-run's events and metrics (SPEC-011).
- The rest of the failure policy: fail-closed rules and 503, the cooldown, the `StorageUnavailable` event and strict mode (SPEC-012).
- Metrics in `terminate()` (ADR-0004 point 4, SPEC-015).
- The emergency bypass and scale (SPEC-013).
- The health checks: a user rule that mostly sees guests, missing `except` paths, two limiters on one route (SPEC-016).
- The dashboard's routes themselves (SPEC-020).
- `dynamic-throttle:explain` (SPEC-014).

## Edge cases and failure modes
- **A route in no group the app attached** is not limited. The docs must say so (ADR-0004).
- **Laravel's `throttle` on the same route.** Both run, and whichever runs last writes the `X-RateLimit-*` headers (ADR-0020 point 9). The docs say to pick one.
- **Authentication middleware Laravel cannot see**, such as a hand-written API key check: user rules count guests until the app lists it in `middleware.after` (ADR-0020 point 4).
- **An app that renders 429s itself**, by catching `ThrottleRequestsException` in its handler, renders ours the same way.
- **A streamed or binary response** still gets the headers, as Laravel's `throttle` adds them.
- **A request to a path in `except` and matched by a rule**: `except` wins, and a rule never overrides it.
- **Octane.** The resolver and the evaluation are per request. The manifest and the scope registry are process-wide, as SPEC-006 and SPEC-008 decided.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate`
- `composer bench` (AC-20)

## Open questions
1. **The class name.** Recommendation: `DynamicThrottleMiddleware`, behind the alias `dynamic.throttle` that ADR-0004 fixed.
   - `ThrottleRequests` reads well, but it shares its short name with Laravel's, which makes imports in `bootstrap/app.php` collide.
2. **Failures before SPEC-012.** Recommendation: let the request through and `report()` each failure, as this spec says.
   - Until SPEC-012 adds the cooldown, an outage reports once per request. SPEC-012 lands before any release, in the same phase.
   - The alternative, letting the request through silently, would hide an outage.
3. **Headers of an allowed request.** Recommendation: only `X-RateLimit-Limit` and `X-RateLimit-Remaining`, as ADR-0008 and Laravel's `throttle` send.
   - `X-RateLimit-Reset` only on the 429, as Laravel does.
   - Among rules with the same remaining, the first in evaluation order, so the headers are stable.
4. **What `DynamicThrottle::fake()` covers.** Recommendation: in-memory counters and manifest cache, and nothing more.
   - Rules still come from the rules table, as in production, so a test saves them with the model.
   - Tests assert with the response: `assertStatus(429)` and its headers. Assertions such as `DynamicThrottle::assertThrottled()` can come later, if users ask.
