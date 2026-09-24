# SPEC-007: Rule matching, pre-index and stacking

- Status: Done
- ADRs: ADR-0006, ADR-0008, ADR-0010, ADR-0012, ADR-0020, ADR-0021, ADR-0023
- Roadmap phase: 2

## Problem
The manifest (SPEC-006) holds every active rule. A request needs the rules that match its method and path, in priority order. Each of them counts the request, and the first enforcing rule that rejects stops evaluation and uncounts the rest (ADR-0008). This has to stay cheap with 100 or 500 active rules. It also must not be bypassable by URL encoding or by method tricks that Laravel's router itself accepts.

## Behavior
- **The request's target.** `RequestTarget::fromRequest()` takes the request as Laravel's router sees it:
  - The method comes from `getMethod()`, so a form's `_method` override counts, as it does in routing.
  - The path comes from `decodedPath()`: URL-decoded, without leading or trailing slashes, and `/` for the root. The router matches on the decoded path, so matching the raw one would let `/api/%75sers` reach the `api/users` route while missing the `api/users` rule.
- **Methods.** A `*` rule matches every method. A `GET` rule also matches `HEAD`, because `Route::get()` registers both, so HEAD requests would otherwise avoid GET limits. Every other method matches only itself.
- **Patterns** use Laravel's `Str::is()` on the decoded path (ADR-0008):
  - `*` matches any characters, slashes included.
  - Matching is case-sensitive and covers the whole path.
  - A leading slash in a pattern is ignored, so `/api/*` works like `api/*`, and the root pattern `/` stays the root.
- **Pre-index.** Rules are grouped by method and by the first path segment of their pattern:
  - The first segment is the text before the first `/`. A first segment that contains `*` goes into a wildcard group, which is tested for every path.
  - A request tests only the rules in its method's groups and `*`'s, for its first segment plus the wildcard group. That is always enough: a pattern whose first segment is literal can only match paths with the same first segment.
  - The index is built when the manifest is loaded, never stored (ADR-0023).
- **Order and cap.** Matches come back in the manifest's order: priority, then id (SPEC-006). Only the first `matching.max_rules_per_request` (default 5) apply. The rest are ignored and counted, and the health check reports them (SPEC-016).
- **Stacking.** `RuleEvaluator::evaluate()` takes the matched rules and an `IdentityResolver`, and goes through the rules in order:
  1. It asks the resolver for the rule's identity hash. `null` means the rule does not apply to this request, for example because of a missing identity with `skip` (ADR-0022, SPEC-008) or an exemption (SPEC-009). The rule is then skipped: no attempt.
  2. It counts the request with `LimiterStore::attempt()`.
  3. A dry-run rule that rejects is recorded as "would block", and evaluation goes on (ADR-0012). Its rejected attempt was not counted (ADR-0006).
  4. The first enforcing rule that rejects stops evaluation. Every attempt counted so far is released, dry-run rules included (ADR-0008, ADR-0021).
- **Storage failures:**
  - A failed release keeps the rejection. The remaining releases still run, and the failures are listed in the result (ADR-0021). Reporting them is SPEC-012's.
  - A failed attempt stops evaluation, with a `Failed` outcome that names the rule, the exception and what was counted. SPEC-012 turns it into fail-open or 503.
- **The result.** An `Evaluation` holds:
  - the outcome: `Allowed`, `Rejected` or `Failed`
  - the rejecting or failing rule, with its attempt or exception
  - every counted attempt, in order, each marked if it was released
  - the dry-run rules that would have blocked
  - the skipped rules and the release failures

  Headers, the 429 and events come from it later (SPEC-010, SPEC-011, SPEC-012).
- **Location.** The matcher and the evaluator live in `src/Engine` (`Engine\Matching`, `Engine\Stacking`). The mutation gate and the engine's arch rules (no database, no wall clock) then cover them. `RequestTarget` lives in `src/Http`.

## Acceptance criteria
Stacking criteria use the cache driver on the array store and a fake clock at T0+10, window 60 s, one identity.

### Request target
- **AC-1** `RequestTarget::fromRequest()`:
  - `GET /api/%75sers/` gives `GET` and `api/users`.
  - `GET /` gives `/`.
  - `POST /api/users` with `_method=DELETE` gives `DELETE`.

### Matching
- **AC-2** Methods, for the path `api/x`:
  - a `*` rule matches `GET`, `POST` and `DELETE`
  - a `POST` rule matches only `POST`
  - a `GET` rule matches `GET` and `HEAD`, not `POST`
  - a `HEAD` rule matches `HEAD`, not `GET`
  - a request whose method is `*` is offered each `*` rule once
- **AC-3** Patterns:

  | Pattern | Matches | Does not match |
  |---|---|---|
  | `api/*` | `api/users`, `api/users/5` | `api`, `apiv2/users` |
  | `api/*/posts` | `api/1/posts`, `api/1/2/posts` | `api/1/posts/9` |
  | `*` | `/`, `api`, `a/b/c` | |
  | `/` | `/` | `api` |
  | `api/users` | `api/users` | `api/users/5`, `API/users` |
  | `/api/*` | `api/users` | `web/users` |
- **AC-4** Matches come back in the manifest's order. With rules at priorities 30, 10 and 20 that all match, all three come back in the order 10, 20, 30, as the manifest lists them.
- **AC-5** The pre-index only offers candidates that can match. Take these rules:
  1. `GET api/*`
  2. `* api/users`
  3. `POST api/*`
  4. `GET web/*`
  5. `* *`
  6. `GET ap*/x`
  7. `* /`

  For `GET api/users`, the candidates are rules 1, 2, 5 and 6, and the matches are rules 1, 2 and 5.
- **AC-6** The pre-index never changes the result. On 200 seeded random rules and 10,000 seeded random requests:
  - `match()` returns exactly what testing every rule with `Str::is()` returns.
  - Patterns mix literal segments, `*` in several places, a leading slash and the root.
  - Methods include `HEAD`.
- **AC-7** The cap:
  - Seven rules match. With `max_rules_per_request` 5, the first five by priority apply, and 2 are ignored.
  - The config value defaults to 5.
  - A value below 1, or one that is not an integer, falls back to 5.
- **AC-8** A manifest with no rules, including a missing one, matches nothing.

### Stacking
- **AC-9** Every matched rule counts an allowed request. The outcome is `Allowed`, with one counted attempt per rule, in order.
- **AC-10** Stacked rules, the stricter one rejecting (ADR-0008's verification). Take `lenient` (max 100, priority 10) and `strict` (max 5, priority 20):
  - Five requests are allowed.
  - The sixth is rejected by `strict`, and `lenient`'s count is released.
  - A seventh request finds `lenient` with remaining 94: its six earlier counts minus the one released.
- **AC-11** The first enforcing rejection stops evaluation. Take `A` (max 1, priority 10) and `B` (max 100, priority 20):
  - The second request is rejected by `A`.
  - `B` is never attempted for it: it still holds 1.
- **AC-12** A dry-run rule never rejects. Take `D` (dry-run, max 1, priority 10) and `E` (max 100, priority 20). The second request:
  - is `Allowed`
  - lists `D` as would-block
  - leaves `D` at 1
  - is counted by `E`
- **AC-13** Dry-run rules are released like enforcing ones (ADR-0021). Take `D` (dry-run, max 100, priority 10) and `S` (max 1, priority 20). The second request:
  - is rejected by `S`
  - leaves `D` at 1, not 2
- **AC-14** A rule whose identity resolves to `null` is skipped. It is not attempted, it is listed as skipped, and the other rules count as usual.
- **AC-15** A failed release keeps the rejection. With a store whose `release()` throws `StorageUnavailableException` for one of two earlier rules:
  - The request is still rejected.
  - The other rule is still released.
  - The failure is listed with its rule.
- **AC-16** A failed attempt stops evaluation, with outcome `Failed`. The result names the rule and the `StorageUnavailableException`, and keeps the attempts counted before it. Nothing is released, and `evaluate()` does not throw.

### Config, arch and benchmark
- **AC-17** New key `matching.max_rules_per_request`, from `DYNAMIC_THROTTLE_MAX_RULES`, default 5, merged by `ConfigMerger`.
- **AC-18 (arch)** `Engine\Matching` and `Engine\Stacking` fall under the engine's rules: no database and no wall clock. `Afzal\DynamicThrottle\Http` touches no database either.
- **AC-19 (benchmark)** PHPBench measures ADR-0008's verification, and the PR reports the numbers. The gate is only that the suite runs:
  - `match()` with 100 and with 500 active rules, 3 of them matching
  - building the index for 100 and 500 rules

## Interfaces and files
- `src/Http/RequestTarget.php`: `fromRequest(Request): self`, with `method` and `path`.
- `src/Engine/Matching/RuleIndex.php`: built from a list of `CompiledRule`. `candidates(string $method, string $path): list<int>` returns positions in manifest order.
- `src/Engine/Matching/RuleMatcher.php`: `match(Manifest, string $method, string $path): MatchResult`.
- `src/Engine/Matching/MatchResult.php`: `rules` (`list<CompiledRule>`) and `ignored` (int).
- `src/Engine/Stacking/RuleEvaluator.php`: `evaluate(list<CompiledRule>, IdentityResolver): Evaluation`.
- `src/Engine/Stacking/Evaluation.php`, `EvaluationOutcome.php` (an enum), `RuleAttempt.php` (a rule's attempt, marked once released) and `RuleFailure.php` (a rule and its `StorageUnavailableException`).
- `src/Engine/Contracts/IdentityResolver.php`: `resolve(CompiledRule): ?string`, the SHA-256 identity hash or `null`. SPEC-008 implements it.
- `src/Manifest/Manifest.php`: gains `index`, built in the constructor (ADR-0023).
- `config/dynamic-throttle.php`: the `matching` section. The provider binds `RuleMatcher` with its cap.
- `benchmarks/Engine/RuleMatcherBench.php`
- Tests:
  - `tests/Feature/Http/RequestTargetTest.php`: AC-1
  - `tests/Unit/Engine/Matching/RuleMatcherTest.php`: AC-2 to AC-8
  - `tests/Unit/Engine/Matching/RuleIndexModelTest.php`: AC-6
  - `tests/Unit/Engine/Stacking/RuleEvaluatorTest.php`: AC-9 to AC-16
  - `tests/Unit/ConfigTest.php`: AC-7 and AC-17 (the env value)
  - `tests/Feature/ServiceProviderTest.php`: AC-7 (the container's matcher, with a published value)
  - `tests/Arch/ArchTest.php`: AC-18

## Out of scope
- Resolving identities (SPEC-008) and exemptions (SPEC-009): only the `IdentityResolver` contract is defined here.
- Headers, the 429, `response_override`, and the `except` list (SPEC-010).
- Would-block events and metrics (SPEC-011, SPEC-015).
- What a failed attempt or release does to the response, logging and the cooldown (SPEC-012). This spec only returns the facts.
- The scale factor and bypass switches (SPEC-013).

## Edge cases and failure modes
- **URL encoding:** handled by matching the decoded path, as the router does (AC-1).
- **An encoded slash.** `api%2Fusers` decodes to `api/users`, as in routing.
- **A request method of `*`.** Symfony keeps any method the server passes on, and `*` is a valid HTTP token. Only `*` rules match it, and each counts it once (AC-2).
- **Case.** Paths and patterns are case-sensitive, like Laravel's routes. `API/users` does not reach an `api/users` route either.
- **Trailing slashes** are trimmed by `decodedPath()`, the same as in routing.
- **A trailing slash in a pattern** (`api/users/`) never matches, because target paths have none. Trimming it, or rejecting it when a rule is saved, is left to the maintainer (raised in PR #4).
- **CORS preflights** (`OPTIONS`) are answered by Laravel's global `HandleCors` middleware before routing, so route middleware never sees them. Without it, a `*` rule would count them.
- **The cap hides rules.** Rules beyond the fifth match never count. The health check (SPEC-016) and `explain` (SPEC-014) show which were ignored.
- **Many wildcard-first patterns** (such as `*/admin`) are tested on every request. The index cannot narrow them, and the benchmark shows the cost.

## End-to-end verification
- `composer test`, `composer analyse`, `composer test:mutate` (the new engine code is in the gate)
- `composer bench`: the matcher subjects run, and the PR reports their numbers

## Open questions
None. The maintainer accepted all five recommendations on 2026-09-24:
1. The pre-index is built when the manifest loads, never stored. ADR-0023 records it, and amends ADR-0008.
2. `GET` rules also match `HEAD`.
3. A leading slash in a pattern is ignored.
4. The matcher and the evaluator live in `src/Engine`.
5. A failed attempt stops evaluation with a `Failed` outcome, and `evaluate()` does not throw.
