# SPEC-011: Dry-run

- Status: In progress
- ADRs: ADR-0008, ADR-0012, ADR-0013, ADR-0020, ADR-0021, ADR-0025
- Roadmap phase: 2

## Problem
Dry-run is the package's main differentiator (ADR-0001): try a limit on real traffic and see who it would block before enforcing it. SPEC-007 and SPEC-010 already count dry-run rules like enforcing ones, never reject with them, set no headers from them, and release them like enforcing rules (ADR-0021). What is missing is the part ADR-0012 calls "when it would reject": nothing tells the app that a dry-run rule would have blocked a request.

## Behavior
- **`RateLimitWouldBlock`** is dispatched when a dry-run rule would have rejected a request: its attempt was not allowed (ADR-0012).
  - One event per dry-run rule that would block, in evaluation order. Two such rules on one request dispatch two events.
  - It fires exactly when an enforcing twin of the rule, fed the same traffic, would reject.
  - It never fires for an allowed attempt, an exempt client, a rule skipped for a missing identity, a rule over the cap, or a rule after the one that rejected the request: evaluation stopped there (ADR-0008), as it would for an enforcing twin.
  - A dry-run rule evaluated before the rule that rejects does fire, although the request gets a 429 anyway: its enforcing twin would have rejected the request first.
- **What it carries** (decision 3): plain values, so a queued listener can serialize it, and no raw identity (ADR-0024):
  - the rule's id and name, its limit, window and scope;
  - the identity's hash, the key its counter uses;
  - the seconds the client would have waited (`Retry-After`);
  - the request's method and path.

  A listener that needs more, such as the client IP to log, reads the request itself.
- **When it fires** (decision 2): after the response, in the middleware's `terminate()`, as ADR-0013 point 1 records telemetry. `handle()` keeps the would-block attempts in the request's attributes, and `terminate()` dispatches them.
  - On PHP-FPM and Octane, listeners never add to the response time.
  - It is dispatched even when nothing listens. Laravel's `Event::fake()` asks the real dispatcher whether a listener exists, so skipping the event then would hide it from an app's own tests. Only a dry-run rule over its limit builds one.
- **A listener that throws** is reported with `report()`, and the next event is still dispatched. Nothing reaches the response, which is already sent (ADR-0013 point 7, ADR-0020 point 1).
- **A dry-run rule that would block releases nothing** (ADR-0021): the rules counted before it keep their counts, since the request is not rejected.
- **Metrics** (decision 1): the `would_blocks` counter, and the "N requests from M clients" the dashboard shows, come with ADR-0013's telemetry buffer in SPEC-015. So do `RateLimitExceeded`, `StorageUnavailable` (SPEC-012) and `RuleChanged`.

## Acceptance criteria
Feature tests on real routes through Testbench, with rules saved through the model, the clock at the start of a 60-second window, and `Event::fake([RateLimitWouldBlock::class])` unless stated otherwise.

- **AC-1** A dry-run rule of 2 per minute on `api/*` dispatches no event for the first 2 requests, and one `RateLimitWouldBlock` for the third, with:
  - the rule's id and name, limit 2, window 60 and scope `ip`;
  - the hash its counter uses for the client;
  - `retryAfter` 90, as SPEC-001 computes it;
  - method `GET` and path `api/x`.

  It comes back unchanged from `serialize()` and `unserialize()`, as a queued listener gets it (decision 3).
- **AC-2** The event is dispatched when the kernel terminates the request, not before: once the HTTP kernel's `handle()` has returned the response, no event is dispatched yet; after its `terminate()`, one is.
- **AC-3** Twins: an enforcing rule and a dry-run rule with the same limit, window and scope, on separate paths, fed the same sequence of 20 requests spread over 3 windows with `travel()`. The dry-run rule's events come on exactly the requests the enforcing rule answers 429, and both keep equal counts.
- **AC-4** Two dry-run rules that would block one request dispatch two events, in evaluation order.
- **AC-5** No event:
  - for an exempt client;
  - for a rule that skips the request for a missing identity;
  - for a dry-run rule over its mode's cap (ADR-0025);
  - for a dry-run rule after an enforcing rule that rejected the request. One before it still dispatches one;
  - with `enabled` off, or on a path in `except`.
- **AC-6** A dry-run rule that would block releases nothing (ADR-0021): with an enforcing rule of 10 evaluated before a dry-run rule of 2, the fourth request shows `X-RateLimit-Remaining: 6`. The enforcing rule kept the counts of the third and fourth requests, which the dry-run rule would block.
- **AC-7** A listener that throws does not change the response, is reported once, and the second of two events is still dispatched.
- **AC-8** A failed evaluation (SPEC-010's Failed outcome) still dispatches: with a dry-run rule of 1 evaluated before a rule whose custom scope throws, the second request dispatches one event, and the scope's error is reported.
- **AC-9 (arch)** `Events` holds final readonly classes, touches no database, reads no clock and never takes the request (decision 3).

## Interfaces and files
- `src/Events/RateLimitWouldBlock.php`: a final readonly class with public properties, in this order:
  - `ruleId` (string) and `ruleName` (string);
  - `limit` (int) and `windowSeconds` (int);
  - `scope` (`Scope`);
  - `identityHash` (string);
  - `retryAfter` (int), in whole seconds;
  - `method` (string), as the request sent it, and `path` (string), as rules match it: decoded, without slashes at either end.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: `handle()` keeps the would-block attempts on the request; `terminate()` dispatches the events.
- Tests:
  - `tests/Feature/Http/DryRunEventTest.php`: AC-1 to AC-8
  - `tests/Arch/ArchTest.php`: AC-9

## Out of scope
- Metrics: `hits`, `blocks` and `would_blocks` per rule and minute, offenders, flushing and pruning (ADR-0013, SPEC-015).
- `RateLimitExceeded` and `RuleChanged` (SPEC-015), and `StorageUnavailable` (SPEC-012).
- The dashboard's "would have blocked N requests from M clients" and the audited "Promote to enforcing" (SPEC-022).
- Sampling and `telemetry.enabled` (ADR-0013 point 7, SPEC-015).
- Shadow copies of a rule (ADR-0012's deferred alternative).

## Edge cases and failure modes
- **A rule changed from enforcing to dry-run, or back,** keeps its counters: the mode is not part of the key (ADR-0012, SPEC-010 AC-12).
- **A dry-run rule after the rule that rejects** is never evaluated, so it neither counts nor dispatches. An enforcing twin in its place would not count either, so the numbers still match what enforcing would do.
- **A failed evaluation** (SPEC-010's Failed outcome) still dispatches the events of the dry-run rules that would have blocked before the failure (AC-8).
- **`terminate()` never throws.** Whatever fails there is reported, as in `handle()` (ADR-0020 point 1).
- **A listener queued with `ShouldQueue`** gets plain values, so the event serializes.
- **Octane.** The would-block attempts live on the request, which is per request, never on the middleware or in static state.
- **A route with the middleware attached twice** is deduplicated by Laravel, so `terminate()` runs once.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`

## Open questions
None. The maintainer accepted all four recommendations on 2026-09-27:
1. **Scope.** This spec adds the event only. The `would_blocks` metric and the distinct clients the dashboard shows need ADR-0013's telemetry buffer, which SPEC-015 builds with the other counters, the flush and the prune. Building the buffer now for one counter would split it across two phases.
2. **When the event fires.** After the response, from `terminate()`, as ADR-0013 point 1 decides for telemetry.
   - Dispatching in `handle()` would be simpler, but a slow sync listener, such as a Slack notification, would delay the response.
   - Laravel builds a fresh middleware for `terminate()`, so the attempts travel on the request's attributes, which also keeps them per request under Octane.
3. **What the event carries.** Plain values (rule, limit, window, scope, identity hash, retry-after, method, path), not the `Request`.
   - Laravel's `Lockout` event carries the request, but a request cannot be serialized, so a queued listener would fail.
   - The identity is carried as its hash, as everywhere else (ADR-0024). A sync listener can still read `request()->ip()` or the user.
4. **`RateLimitExceeded` waits** for SPEC-015, with the other integration events. The `terminate()` path this spec builds makes it a small addition there.
