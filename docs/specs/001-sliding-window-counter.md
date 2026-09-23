# SPEC-001: Sliding-window counter formula

- Status: In progress
- ADRs: ADR-0006
- Roadmap phase: 1

## Problem
Every driver must make the same allow/reject decision for the same counts. The math has to live in one pure, fully tested place. The Redis Lua script will be a port of it (SPEC-003).

## Behavior
Given the previous window's count, the current window's count (before this request), how far we are into the current window, the window length and the limit, decide:

- whether this request is allowed
- how many requests remain
- how long to wait before retrying, when rejected

Formula (ADR-0006):

- `est = previous * (window - elapsed) / window + current`
- allowed if `est + 1 <= max`
- remaining (when allowed) = `max(0, max - ceil(est + 1))`
- retryAfter = smallest whole number of seconds `t ≥ 1` after which a request would be allowed, assuming no new traffic

## Acceptance criteria
All with `window = 60`, `max = 10` unless stated.

- **AC-1** previous 0, current 9, elapsed 30 → allowed, remaining 0.
- **AC-2** previous 0, current 10, elapsed 30 → rejected.
- **AC-3** previous 10, current 0, elapsed 30 → allowed, remaining 4 (est 5, +1 = 6).
- **AC-4** previous 10, current 4, elapsed 30 → allowed, remaining 0 (5 + 4 + 1 = 10).
- **AC-5** previous 10, current 0, elapsed 0 → rejected, retryAfter 6.
- **AC-6** previous 10, current 5, elapsed 30 → rejected, retryAfter 6. Previous must decay to 4; that happens at elapsed 36.
- **AC-7** previous 0, current 10, elapsed 30 → rejected, retryAfter 36. That is 30 s to the next window, plus 6 s until the carried-over 10 decays to 9.
- **AC-8** previous 5, current 0, elapsed 30 → allowed, remaining 6 (est 2.5, +1 = 3.5, ceil 4).
- **AC-9** previous 7, current 6, elapsed 30 → rejected, retryAfter 5. The exact value is 4.29 s, rounded up.
- **AC-10** window 3600, max 100, previous 100, current 20, elapsed 900 → allowed, remaining 4 (75 + 20 + 1 = 96).
- **AC-11** Whenever a request is rejected, retryAfter ≥ 1. Whenever it is allowed, retryAfter = 0.
- **AC-12** Invalid input throws `InvalidArgumentException`:
  - max < 1
  - window < 1
  - elapsed < 0
  - elapsed ≥ window
  - elapsed is NaN (added during implementation: NaN passes both elapsed checks above, because every comparison with NaN is false)
  - previous < 0
  - current < 0

## Retry-after solution (for the implementer)
- **Case A: still in this window.** If `current + 1 <= max` and `previous > 0`:
  `t = window - elapsed - (max - current - 1) * window / previous`.
  If `t < window - elapsed`, this is the answer.
- **Case B: next window.** Otherwise, the next window starts with `previous' = current` and `current' = 0`:
  - `e' = max(0, window - (max - 1) * window / current)`
  - `t = (window - elapsed) + e'`
- Round `t` up to a whole second, with a minimum of 1.

## Interfaces and files
- `src/Engine/Algorithm/SlidingWindowCounter.php`: `final readonly class`, no dependencies.
  `evaluate(int $previous, int $current, float $elapsed, int $windowSeconds, int $maxAttempts): Evaluation`
- `src/Engine/Algorithm/Evaluation.php`: `final readonly class` with `bool $allowed`, `int $remaining`, `int $retryAfter`, `float $estimate`.
- `tests/Unit/Engine/Algorithm/SlidingWindowCounterTest.php`: Pest datasets, one row per acceptance criterion, plus `covers(SlidingWindowCounter::class)`.

## Out of scope
Storage, keys, the time source, the Lua port (SPEC-003), HTTP headers (SPEC-010).

## Edge cases and failure modes
- Floating-point: compare with the formula exactly as written; do not round `est` before comparing.
- `previous = 0` must not divide by zero (go to case B, or allow).

## End-to-end verification
- `composer test -- --filter=SlidingWindowCounter` is green.
- `composer analyse` is clean.
- `composer test:mutate` gives a mutation score ≥ 85%. It mutates everything in `src/Engine` (ADR-0016), not only classes a test names in `covers()`.
  - Changed during implementation. The original command, `vendor/bin/pest --mutate --filter=SlidingWindowCounter`, over-reports. Pest keeps the user's `--filter` and adds its own for each mutant. PHPUnit 12 then warns "Option --filter cannot be used more than once", `failOnPhpunitWarning` makes that exit 1, and Pest counts any failing subprocess as a killed mutant. It showed 100% while mutants survived. This is how PHPUnit behaves, not a Pest bug a later version will fix. Never pass `--filter` to `--mutate`; use `--class` or `--path` to narrow the run.
  - Result on 2026-09-23: every mutant in `src/Engine` killed (145 of 145, with SPEC-002 included). An earlier run looked as if two mutants of `headroom > 0` could never be killed. In floats they can: see the test "retryAfter agrees with the limiter's own arithmetic at a floating-point edge".

## Implementation notes
- Case A applies when `headroom = max − current − 1 > 0`. For a rejected request this implies `previous > 0`, so the division is safe, and it is the spec's case A condition.
- Case B divides by `current`, which is 0 when `max = 1` and only the previous window has traffic. The next window then allows at once, so the wait is `window − elapsed`.
- `retryAfter` is exact **with respect to the limiter's own floating-point arithmetic**, and that is what decides the retried request. In exact real arithmetic it can differ by one second when the exact wait lies within a few units of float precision (about 1e-15 s) of a whole second. Example: previous 1, current 9, elapsed 54.999999999999993 gives 5, where exact arithmetic says 6. The request retried after 5 s is allowed. A review measured 0 such cases in 40,000 random inputs; they are harmless.
- "Minimum of 1" is not just defensive. With float elapsed values from a real clock, a request can be rejected by about 1e-15 while the closed-form wait computes to exactly 0 (previous 11, current 0, elapsed 5.4545454545454541, window 10, max 6). A test covers it.

## Open questions
None.
