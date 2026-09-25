# SPEC-010: Middleware, priority, headers and 429

- Status: In progress
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
- **The middleware** `DynamicThrottleMiddleware`, with the alias `dynamic.throttle` (ADR-0004 point 1, decision 1). The app adds it to route groups or routes. No route parameters: rules decide everything (ADR-0004).
- **Nothing is attached by default** (ADR-0020 point 11):
  - The package registers no global middleware.
  - `middleware.auto_attach` (default `[]`) lists route groups, such as `['api']`, to append the middleware to at boot.
  - A group the app has not defined is skipped, never thrown on, because a config mistake must not take the app down (ADR-0020 point 1). SPEC-016's config check reports it.
- **It runs after authentication** (ADR-0004 point 2, ADR-0020 point 4). At boot, the package places the middleware in Laravel's middleware priority list right after:
  - Laravel's authentication middleware (`AuthenticatesRequests`);
  - every middleware in `middleware.after` (default `[]`), as aliases or classes, such as a JWT package's.

  So a group that lists `dynamic.throttle` before `auth` still runs it after `auth`. In detail:
  - The package does this once every provider has booted, through Laravel's HTTP kernel. So `middleware.after` can name aliases that other packages register, and later changes to the kernel keep it.
  - An alias's parameters, such as `:api` in `auth:api`, are ignored.
  - A middleware of `middleware.after` that the priority list does not hold is added to it, right before this one.
  - An app that already placed the middleware in its priority list, as ADR-0004's fallback shows, keeps that place.
  - An HTTP kernel that is not Laravel's gets neither the priority nor `auto_attach`. The docs show how to set both up by hand (ADR-0004's fallback).
- **For each request**, in this order, the middleware stops at the first step that lets the request through untouched:
  1. **The kill switch.** With `enabled` off, it does nothing (ADR-0011 point 4).
  2. **The package's own routes.** A route whose name starts with `dynamic-throttle.`, such as the dashboard's and its assets' (SPEC-020), is never limited, whatever the rules and `except` say (ADR-0004 point 5).
  3. **`except`.** Path patterns that no rule ever applies to (ADR-0020 point 3). They are checked like rule patterns: `Str::is` on the decoded path, with a leading slash ignored (SPEC-007). The defaults are ADR-0020's: `horizon/*`, `telescope/*`, `pulse`, `pulse/*`, `nova/*`, `nova-api/*`, `livewire/*`, `broadcasting/auth`, `sanctum/csrf-cookie` and `up`.
  4. **The manifest** (SPEC-006) and **matching** (SPEC-007). No matched rule: the request goes through.
  5. **Evaluation** (SPEC-007), with this request's resolver and the manifest's exemptions (SPEC-008, SPEC-009).
- **The outcome:**
  - **Rejected:** the middleware throws Laravel's `ThrottleRequestsException`, so the app's exception handler renders the 429 like Laravel's own (ADR-0008). The counts the other rules made are released (ADR-0021). Headers, from the rejecting rule:
    - `Retry-After`: the attempt's whole seconds (ADR-0006)
    - `X-RateLimit-Reset`: the Unix time the request may retry, as Laravel sends it: now plus `Retry-After`
    - `X-RateLimit-Limit`: the rule's limit
    - `X-RateLimit-Remaining`: `0`
  - **A rejecting rule with a `response_override`** answers 429 with that JSON body and the same headers, instead of the handler's page (ADR-0008). Like Laravel's `Limit::response()`, it throws an `HttpResponseException`.
  - **Allowed:** the request goes on. Its response gets `X-RateLimit-Limit` and `X-RateLimit-Remaining` from the most restrictive enforcing rule that counted it: the lowest remaining, and the first in evaluation order among equals (ADR-0008, decision 3). A request that no enforcing rule counted gets no headers, for example when every matched rule is dry-run, exempt or skipped.
  - **Dry-run rules** count, never reject and set no headers (ADR-0012). A dry-run rule matched before the rule that rejects is released too (ADR-0021). Their events and metrics are SPEC-011's.
  - **Failed** (a counter or an identity could not be used): the request goes through (decision 2), with the headers of the enforcing rules that did count it, as an allowed request gets them. SPEC-012 adds the rest of the failure policy: fail-closed rules and 503, the cooldown, the event and strict mode.
- **Reporting.** Every failure the evaluation lists is reported once with `report()` (ADR-0020 point 1):
  - rules that failed;
  - releases that failed, which keep the 429 (ADR-0021);
  - exemption checks that failed (SPEC-009).

  An exception that several rules share, such as a failing custom scope's, is reported once. Until SPEC-012 adds the cooldown, an outage reports once per request (decision 2).
- **Only the 429 leaves the middleware** (ADR-0020 point 1). Anything else the package throws while deciding, such as an invalid `driver`, `store` or `prefix`, is reported, and the request goes through without headers. The app's own exceptions, thrown after the middleware passed the request on, are never touched.
- **`DynamicThrottle::fake()`** is for the app's own tests (ADR-0020 point 12, decision 4). It moves the counters and the manifest into a fresh in-memory store, so tests need no Redis and share no counts:
  - The counters read the time from Laravel's clock, so `travel()` moves their windows.
  - Rules still come from the rules table. `fake()` compiles the ones already saved, such as a seeder's, so they apply from the first request. Rules saved later compile as usual.
  - It clears the manifest the process keeps in memory (SPEC-006), so a test never sees the rules of the test before it.
  - Without the package's tables, no rule applies, and nothing is reported.
  - A test asserts a limit through the response's status and headers.
- **Cost.** A request whose path matches no rule costs a manifest read and one index lookup. The database is never queried (ADR-0007).

## Acceptance criteria
Feature tests on real routes through Testbench, with rules saved through the model and the manifest compiled, unless stated otherwise. The clock stands at the start of a 60-second window.

### Wiring
- **AC-1** The alias `dynamic.throttle` resolves to `DynamicThrottleMiddleware`, and the package adds it to no global middleware and no group.
- **AC-2** With the default config, a `*` rule of 1 per minute, and routes in `web` and `api`, no route is limited: the second request is not a 429 (ADR-0020 point 11). The same rule limits a route that lists `dynamic.throttle`.
- **AC-3** With `middleware.auto_attach` = `['api', 'missing']`:
  - The app boots, although it has no `missing` group.
  - A route in the `api` group is limited, and one in `web` is not.
- **AC-4** Priority:
  - A group that lists `dynamic.throttle` before `auth` counts an authenticated user under a `user` rule as that user, not as a guest.
  - A custom authentication middleware listed in `middleware.after`, by alias and by class, and placed after `dynamic.throttle` in the group, still runs before it.
  - Without `middleware.after`, that middleware runs after, and its users count as guests (ADR-0020 point 4).

### Skipping
- **AC-5** With `enabled` off, a limited route is never limited, sets no headers, and reads nothing from the cache: no manifest and no counter.
- **AC-6** With a `*` rule of 1 per minute:
  - `livewire/update`, `horizon/api/stats` and `up` are never limited.
  - With `livewire/*` removed from `except`, `livewire/update` is limited.
  - A leading slash in an `except` pattern is ignored: `/reports/*` excludes `reports/daily`.
- **AC-7** A route named `dynamic-throttle.dashboard` is never limited, even with an empty `except`. A route named `dynamic-throttled.home` is.

### Responses
- **AC-8** Allowed: with enforcing rules of 10 and 5 per minute on a path, the response carries `X-RateLimit-Limit: 5` and `X-RateLimit-Remaining: 4`. With two rules at the same remaining, the headers come from the first in evaluation order.
- **AC-9** Rejected:
  - The third request under a limit of 2 answers 429 through `ThrottleRequestsException`, rendered by the app's handler: a handler that renders that exception itself renders ours.
  - It carries `Retry-After: 90`, `X-RateLimit-Reset` (now plus 90, in Unix seconds), `X-RateLimit-Limit: 2` and `X-RateLimit-Remaining: 0` (SPEC-001's retry for 2 requests at the start of a 60-second window).
  - A lenient rule on the same path does not count the rejected request (ADR-0021).
- **AC-10** A rejecting rule of 1 per minute with `response_override` `{"message": "Slow down"}` answers 429 with exactly that JSON body, and the AC-9 headers: `Retry-After: 120`, `X-RateLimit-Reset`, `X-RateLimit-Limit: 1` and `X-RateLimit-Remaining: 0`.
- **AC-11** No headers:
  - on a path where the only matched rule is dry-run, even once it would block;
  - for a client exempt from every matched rule;
  - on a path no rule matches.
- **AC-12** Dry-run (ADR-0012, ADR-0021):
  - A dry-run rule of 1 per minute never answers 429, and still counts: promoted to enforcing, it rejects the next request.
  - It never gives the headers, even when it is more restrictive than the enforcing rule.
  - Matched before the rule that rejects, it is released: promoted afterwards, it has counted the same as an enforcing twin.
- **AC-13** `HEAD` is limited by a `GET` rule, and a `POST` rule leaves a `GET` alone (SPEC-007).
- **AC-14** A `user` rule counts two users apart, and a client exempt by IP is never limited (SPEC-008, SPEC-009).

### Failures
- **AC-15** Each of these lets the request through without headers, and reports its error once:
  - a counter store that throws;
  - an invalid `driver`, an undefined `store` and an invalid `prefix`;
  - a corrupt manifest;
  - a custom scope whose resolver throws, used by two rules and by an exemption.

  A release that fails keeps the 429, and reports its error once (ADR-0021).
- **AC-16** An exception the route itself throws reaches the app's handler unchanged, and the middleware reports nothing. The request still counted.

### Test helper and cost
- **AC-17** `DynamicThrottle::fake()`:
  - With `driver` set to `redis` and no Redis to reach, a route over its limit answers 429. Run twice, the test starts from zero both times.
  - Rules saved before `fake()` apply from the first request.
  - Without the package's tables, `fake()` works: no rule applies, and nothing is reported.
- **AC-18** An allowed and a rejected request, with the manifest compiled in the cache and read from it as on PHP-FPM, run no database query (query-count assertion, the Phase 2 exit criterion).
- **AC-19 (arch)** `Http`, with `Http\Middleware`, touches no database (existing rules) and reads the time only through `Clock`.
- **AC-20 (benchmark)** A middleware benchmark: a request through the middleware with 100 rules, 3 of them matched, on local Redis and on the array store, with the manifest in memory (Octane, queue workers) and read from the cache on every request (PHP-FPM). The results record, with the hardware, the p95 it adds, against the roadmap's 1 ms target.

## Interfaces and files
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: `handle()`.
- `src/Http/RateLimitHeaders.php`: the headers of a counted and of a rejected request, from an `Evaluation`.
- `src/DynamicThrottleServiceProvider.php`: the alias, and, once every provider has booted, `auto_attach` and the priority.
- `src/DynamicThrottle.php`: `fake()`, which `src/Facades/DynamicThrottle.php` lists.
- `config/dynamic-throttle.php`: `middleware.auto_attach`, `middleware.after` and `except`, with comments.
- `README.md`: attaching the middleware, `except`, `middleware.after`, and `fake()`.
- Tests:
  - `tests/Feature/Http/MiddlewareWiringTest.php`: AC-1 to AC-4
  - `tests/Feature/Http/MiddlewareSkippingTest.php`: AC-5 to AC-7
  - `tests/Feature/Http/MiddlewareResponseTest.php`: AC-8 to AC-14
  - `tests/Feature/Http/MiddlewareFailureTest.php`: AC-15, AC-16
  - `tests/Feature/Http/FakeTest.php`: AC-17
  - `tests/Feature/Http/MiddlewareQueryTest.php`: AC-18
  - `tests/Arch/ArchTest.php`: AC-19
  - `tests/Unit/ConfigTest.php`: the new keys' defaults (AC-2, AC-6)
  - `tests/Support/Throttling.php` (rules, routes, rewiring, headers), `tests/Support/FailingStore.php` and `tests/Support/Http/HeaderAuthenticate.php` (an authentication middleware Laravel cannot see)
- `benchmarks/Http/MiddlewareBench.php` and `benchmarks/results/phase-2-middleware.md`: AC-20

## Out of scope
- Dry-run's events and metrics (SPEC-011).
- The rest of the failure policy: fail-closed rules and 503, the cooldown, the `StorageUnavailable` event and strict mode (SPEC-012).
- Metrics in `terminate()` (ADR-0004 point 4, SPEC-015).
- The emergency bypass and scale (SPEC-013).
- The health checks: a user rule that mostly sees guests, missing `except` paths, two limiters on one route, and config checks such as an unknown `auto_attach` group (SPEC-016).
- The dashboard's routes themselves (SPEC-020).
- `dynamic-throttle:explain` (SPEC-014).
- Assertions such as `DynamicThrottle::assertThrottled()` (decision 4).

## Edge cases and failure modes
- **A route in no group the app attached** is not limited. The docs must say so (ADR-0004).
- **Laravel's `throttle` on the same route.** Both run, and whichever runs last writes the `X-RateLimit-*` headers (ADR-0020 point 9). The docs say to pick one.
- **Authentication middleware Laravel cannot see**, such as a hand-written API key check: user rules count guests until the app lists it in `middleware.after` (ADR-0020 point 4).
- **An app that renders 429s itself**, by rendering `ThrottleRequestsException` in its handler, renders ours the same way (AC-9).
- **A streamed or binary response** still gets the headers, as Laravel's `throttle` adds them.
- **A request to a path in `except` and matched by a rule**: `except` wins, and a rule never overrides it.
- **An exception from the route** is rendered by Laravel's router pipeline inside the middleware, so its error page also gets the headers, as with Laravel's `throttle`.
- **Tests that do not call `fake()`** can see the rules the test before them compiled, for up to `manifest.refresh_seconds`, because the manifest stays in process memory (SPEC-006). The docs say to call `fake()` in every test that sends requests through the middleware, for example in the base test case's `setUp()`.
- **Octane.** The resolver and the evaluation are per request. The manifest and the scope registry are process-wide, as SPEC-006 and SPEC-008 decided.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate`
- `composer bench` (AC-20)

## Open questions
None. The maintainer accepted all four recommendations on 2026-09-25:
1. The class is `DynamicThrottleMiddleware`, behind the alias `dynamic.throttle` that ADR-0004 fixed. `ThrottleRequests` reads well, but it shares its short name with Laravel's, which makes imports in `bootstrap/app.php` collide.
2. Until SPEC-012, a failure lets the request through, and each one is reported with `report()`. Until SPEC-012 adds the cooldown, an outage reports once per request, and SPEC-012 lands before any release, in the same phase. Letting the request through silently would hide an outage.
3. An allowed request gets only `X-RateLimit-Limit` and `X-RateLimit-Remaining`, as ADR-0008 and Laravel's `throttle` send. `X-RateLimit-Reset` comes only with the 429, as in Laravel. Among rules with the same remaining, the first in evaluation order gives them, so the headers are stable.
4. `DynamicThrottle::fake()` covers in-memory counters and the manifest's cache, and nothing more. Rules still come from the rules table, as in production, so a test saves them with the model, and asserts with the response: `assertStatus(429)` and its headers. Assertions such as `DynamicThrottle::assertThrottled()` can come later, if users ask.
