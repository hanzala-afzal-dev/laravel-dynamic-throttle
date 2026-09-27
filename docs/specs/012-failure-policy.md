# SPEC-012: The failure policy

- Status: Draft
- ADRs: ADR-0008, ADR-0010, ADR-0013, ADR-0020, ADR-0021, ADR-0025
- Roadmap phase: 2

## Problem
SPEC-010 lets every request through when something fails, and reports each failure once per request (its decision 2). What ADR-0010, ADR-0020 and ADR-0021 decided beyond that is still missing:
- A rule marked `on_failure: closed`, such as one on a login route, protects nothing while its counter cannot be checked.
- During an outage, every request waits for the store to fail again, and reports it again.
- Apps learn about an outage only through their error tracker: there is no `StorageUnavailable` event.
- A programming mistake, such as a scope resolver that throws, is as quiet in development as in production.

## Behavior

### Fail-closed rules answer 503 (ADR-0010 point 2)
- An enforcing rule with `on_failure: closed` answers **503** when it applies to the request but its counter cannot be checked.
  - **It applies:** it matched, the client is not exempt from it, it has an identity (a rule that skips guests does not apply to them), and it is within the cap (ADR-0025).
  - **Its counter cannot be checked** when:
    - the counter store throws, on this rule's attempt or on an earlier rule's;
    - the store is in its cooldown (below);
    - the store cannot be built, because of a config mistake such as an unknown `driver`;
    - the rule's own identity cannot be resolved, such as a custom resolver that throws, or a client IP that cannot be read.
- **The first decision in evaluation order wins.** Evaluation stops at the 503, as at a rejection (ADR-0008): a 429 from an earlier rule stands, and the rules after it never run.
- **Counts made before it** are released when the store works, as for a 429 (ADR-0008). When the store is down they are kept: releasing would only wait for more failures, and a kept count is stricter, never looser (SPEC-004).
- **The response** (decision 2): Symfony's `ServiceUnavailableHttpException`, which the app's exception handler renders as it renders `abort(503)`: its 503 page, or JSON for a JSON request. 503, not 429: the client did nothing wrong (ADR-0010). A rule's `response_override` is for its 429 only.
  - `Retry-After` is what is left of the cooldown, in whole seconds and at least 1. With no cooldown running, such as for an identity that failed, it is `failure.cooldown_seconds`.
- **Only the 429 and the 503 leave the middleware** (ADR-0020 point 1).
- **Never a 503:**
  - from a dry-run rule, which never changes a response (ADR-0012);
  - without a manifest, since then no rule is known (ADR-0010 point 3).

### The cooldown (ADR-0010 point 4)
- When the counter store throws, on an attempt or on a release (ADR-0021), the package skips the store for `failure.cooldown_seconds`: default 10, `DYNAMIC_THROTTLE_COOLDOWN`. 0 turns it off.
- **During the cooldown**, no request touches the counter store:
  - fail-open rules let the request through, and set no headers;
  - fail-closed rules answer 503 at once;
  - dry-run rules count nothing and dispatch nothing.
- **After it**, the next request tries the store again. If that fails, a new cooldown starts.
- **Where it lives** (decision 1): in the process, for Octane and queue workers, and in APCu when the extension is enabled, where the PHP-FPM workers of a server share it. So each server finds an outage on its own, and so does each Octane worker on a server without APCu.

### Reporting (ADR-0020 point 1)
- Every failure is still reported with `report()`, but each cause at most once per cooldown (decision 5). A cause is the exception's class and where it was thrown, so a broken resolver or a config mistake reports once every 10 seconds, not on every request.
- A storage failure reports when it starts a cooldown. During the cooldown nothing touches the store, so nothing more fails.
- The throttle lives where the cooldown does (decision 1).

### `StorageUnavailable` (ADR-0010 point 1, ADR-0013 point 6)
- **When:** the counter store failed and a cooldown started, so once per cooldown in each process or server. Also after a failed release, which keeps its 429 (ADR-0021).
- **After the response,** from the middleware's `terminate()`, as SPEC-011 dispatches `RateLimitWouldBlock`. A listener that throws is reported, and the next event still goes out.
- **What it carries** (decision 3): plain values, so a queued listener works:
  - the error's class and message;
  - whether it was a release;
  - the cooldown, in seconds.

### Strict mode (ADR-0020 point 1)
- `DynamicThrottle::strict()`, off by default, in the style of `Model::shouldBeStrict()`: for example `DynamicThrottle::strict(! app()->isProduction())` in a service provider.
- **In strict mode, the middleware rethrows programming mistakes** instead of applying the policy (decision 4):
  - config mistakes: an unknown `driver`, an undefined `store`, an invalid `prefix`;
  - the app's own code: a custom scope that is not registered, throws, or returns an unusable value; a guard that throws; a user id of an unusable type;
  - unexpected errors from the package itself.
- **Never rethrown**, strict or not, because none of them is a mistake in code:
  - storage errors;
  - a missing, unreadable or corrupt manifest;
  - a client IP that cannot be read, since clients control the forwarded headers.

## Acceptance criteria
Feature tests on real routes through Testbench, with rules saved through the model, the clock at T0, and a counter store that fails on demand and counts its calls. Each test clears the cooldown's and the throttle's state, as the manifest tests do.

- **AC-1 (fail-open and the cooldown)** A fail-open rule on a failing store:
  - The first request gets 200 and no headers. The store was called once, one error is reported, and one `StorageUnavailable` is dispatched after the response.
  - Three more requests before T0 + 10 get 200. They make no store call, no report and no event.
  - At T0 + 10 the store is called again. It still fails, so there is a second report and a second event.
  - With `failure.cooldown_seconds` at 0, every request calls the store and reports.
- **AC-2 (fail-closed)** A fail-closed rule on a failing store answers 503 with `Retry-After: 10`.
  - At T0 + 3 it answers 503 with `Retry-After: 7`, without a store call.
  - The app's handler renders it: a JSON request gets a JSON body.
  - A fail-open rule on the same route, matched before it, does not change the answer.
- **AC-3 (identity)** A fail-closed rule whose custom resolver throws answers 503 with `Retry-After: 10`.
  - A fail-open rule counted before it is released: its remaining on the next request is 4 of 5, not 3.
  - The resolver's error is reported once.
- **AC-4 (no 503)** The request passes for:
  - a dry-run fail-closed rule;
  - an exempt client;
  - a fail-closed user rule that skips guests, for a guest;
  - a fail-closed rule over the cap;
  - a request an earlier rule rejected, which keeps its 429 and does not run the fail-closed rule;
  - a missing manifest.
- **AC-5 (config mistakes)** With an unknown `driver`:
  - a fail-closed rule answers 503;
  - a fail-open rule lets the request through;
  - either way, the error is reported once in 10 seconds.
- **AC-6 (failed release)** A store whose releases fail (ADR-0021):
  - The rejected request keeps its 429.
  - One `StorageUnavailable` is dispatched, marked as a release.
  - The next request makes no store call.
- **AC-7 (the event)** `StorageUnavailable`:
  - carries the error's class and message, whether it was a release, and the cooldown;
  - comes back unchanged from `serialize()`;
  - is dispatched only once the kernel terminates the request;
  - a listener that throws is reported and does not change the response.
- **AC-8 (reports)** A fail-open rule whose custom resolver throws, over five requests before T0 + 10: one report. At T0 + 10, one more.
- **AC-9 (strict mode)**
  - Off by default: an unknown `driver` lets the request through.
  - On, these escape the request:
    - an unknown `driver`, as `InvalidArgumentException`;
    - a throwing resolver, as `IdentityUnavailableException`, with the app's exception as its previous one.
  - On, the policy still applies to:
    - a failing store: 200 for fail-open, 503 for fail-closed;
    - a corrupt manifest;
    - a client IP that cannot be read.
- **AC-10 (APCu)** Where the extension is enabled, a cooldown started by one process is seen by another, which makes no store call. The test is skipped where APCu is missing.
- **AC-11 (arch)**
  - The cooldown and the report throttle keep no state on the middleware or the evaluator.
  - Their keys come from `KeyBuilder`.
  - `Events` stays free of the database, the clock and the request (SPEC-011 AC-9).

## Interfaces and files
- `config/dynamic-throttle.php`: `failure.cooldown_seconds` (`DYNAMIC_THROTTLE_COOLDOWN`, default 10, read as written like the other integers).
- `src/Events/StorageUnavailable.php`: a final readonly class with public properties: `exceptionClass`, `message`, `release` and `cooldownSeconds`.
- `src/DynamicThrottle.php` and its facade: `strict(bool $strict = true): void`.
- The cooldown and the report throttle: new classes in `src/Engine`, reading time from the `Clock`, with keys from `KeyBuilder`.
- `src/Engine/Stacking/RuleEvaluator.php`, `Evaluation` and `EvaluationOutcome`: a fail-closed rule that cannot be checked stops evaluation with a new outcome.
- `src/Http/Middleware/DynamicThrottleMiddleware.php`: the 503, strict mode, the report throttle, and `StorageUnavailable` in `terminate()`.
- `README.md` and `CHANGELOG.md`.
- Tests:
  - `tests/Feature/Http/FailurePolicyTest.php`: AC-1 to AC-8
  - `tests/Feature/Http/StrictModeTest.php`: AC-9
  - `tests/Unit/...`: the cooldown and the throttle on their own, AC-10
  - `tests/Arch/ArchTest.php`: AC-11
- CI and `scripts/cloud-setup.sh` enable APCu (`apc.enable_cli=1`), so AC-10 runs.

## Out of scope
- The manifest's own failures keep SPEC-006's handling. On PHP-FPM the manifest is read from the store on every request, so while the store is down each request waits for that read to fail and finds no rules (ADR-0010 point 3). Fail-closed rules then protect nothing on FPM, unless the manifest lives in memory: ADR-0007's L1 cache (APCu), which the Phase 2 exit decides.
- `dynamic-throttle:status` and the health check's config warnings (SPEC-013, SPEC-016).
- The runbook's "Redis down" scenario (Phase 3 exit), which will reuse AC-1's test.
- A per-server emergency limiter for fail-closed rules (ADR-0010's fallback).
- The other integration events (SPEC-015).

## Edge cases and failure modes
- **A rule the store could not count still takes its slot** under the cap, so which rules apply never depends on the store's health.
- **Several failures in one request** report once per cause: a resolver shared by two rules, an IP that cannot be read (SPEC-010).
- **The clock goes back** during a cooldown, for example after an NTP correction: the cooldown ends, so a clock step can never stretch it past `cooldown_seconds`.
- **APCu is full or disabled at runtime:** the cooldown falls back to the process, never throws.
- **`DynamicThrottle::fake()`:** its in-memory store never fails, and each test starts without a cooldown.
- **Octane:** the cooldown is per worker without APCu. Strict mode is set once at boot, and lives for the worker.

## End-to-end verification
- `composer test`, `composer analyse`, `composer format:check`, `composer refactor:check`
- `docker compose up -d && composer test:drivers`
- `composer test:mutate`, since the cooldown and the evaluator are in `src/Engine`

## Open questions
1. **The cooldown on PHP-FPM without APCu.** Recommendation: as ADR-0010 decides, keep it in the process and in APCu, and document that PHP-FPM without APCu keeps nothing between requests: each request tries the store once, and reports. The docs recommend APCu.
   - The alternative, a small file in Laravel's storage directory, costs one file check per request on every FPM app, and rarely helps. On FPM the manifest is read from the same store first, so during an outage requests find no rules before they reach the counters.
   - The throttle of reports lives with it, so on FPM without APCu reports are not throttled. ADR-0020 counted on the cooldown for that.
2. **The 503.** Recommendation, as above:
   - Symfony's `ServiceUnavailableHttpException`, rendered by the app's handler.
   - `Retry-After` is what is left of the cooldown.
   - `response_override` stays 429-only.
   - Counts made before it are released when the store works.
3. **What `StorageUnavailable` carries.** Recommendation: the error's class and message, whether it was a release, and the cooldown in seconds.
   - Not the exception itself: a trace can hold closures, which cannot be serialized, so a queued listener would fail.
   - Not the store's name or host: a listener can read the config.
4. **What strict mode rethrows.** Recommendation, as above:
   - Rethrown: config mistakes, the app's own resolvers and guards, and unexpected package errors.
   - Never: storage, the manifest, or an unreadable client IP.
5. **The throttle of reports.** Recommendation: each cause at most once per cooldown, where a cause is the class and the throw site of the root exception. Error trackers group reports the same way.
   - Throttling by class alone would hide a second, different failure of the same class.
   - Throttling by message would let a message that carries a changing value, such as an id, flood the tracker.
