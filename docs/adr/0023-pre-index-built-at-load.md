# ADR-0023: The rule pre-index is built when the manifest loads

- Status: Accepted
- Date: 2026-09-24
- Roadmap phase: 2
- Amends: [ADR-0008](0008-rule-matching.md) ("The compiler pre-indexes rules")
- Amended by: [SPEC-028](../specs/028-manifest-apcu-cache.md) (L1, the manifest's copy in APCu, stores the index with the rules it was built from)
- Related: ADR-0007, SPEC-006, SPEC-007

## Context

- ADR-0008 says the compiler pre-indexes rules by method and first literal path segment, so a request only tests candidate rules.
- If the compiler stored the index in the manifest, the manifest would need a new format and strict checks, because an index that disagreed with its rules would match the wrong ones.
- The index is derived data: the rules alone decide it.

## Decision

- The index is built from the rules when a `Manifest` is constructed, which happens when the manifest is loaded. It is never stored.
- The compiler still sorts the rules by priority, then id (SPEC-006). The index returns candidates in that order.

## Reasoning

- A derived index can never disagree with the rules it indexes.
- The cost is the same order as decoding:
  - On PHP-FPM, every request already decodes the whole manifest, which is linear in the number of rules. Building the index is one more linear pass.
  - On Octane and queue workers, the manifest stays in memory (ADR-0007 L0), so the index is built once per refresh.
- No manifest format change, and no index validation to test.

## Alternatives considered

- **Store the index in the manifest,** as ADR-0008 wrote. The index could drift from the rules, and it needs its own format and checks, for no gain in complexity class. Rejected.
- **No index,** testing every rule. That is linear in the number of rules for every request, including `Str::is()` calls. Rejected: ADR-0008's point is to bound matching cost.

## Fallback

- If the Phase 2 benchmark shows index building matters on PHP-FPM, store the index in the manifest after all, with a format bump. SPEC-006's format check rebuilds old manifests.
- **Revisit when:** the benchmark with 500 rules shows index building above the decode cost.

## Consequences

- Good: one source of truth, nothing to keep in sync.
- Bad: FPM requests pay one linear pass per request. The benchmark measures it (SPEC-007 AC-19).

## Verification

- SPEC-007 AC-6: on seeded random rules and requests, indexed matching returns exactly what testing every rule returns.
- SPEC-007 AC-19: PHPBench measures index building for 100 and 500 rules.

## Decision record

On 2026-10-01, for SPEC-028, the maintainer decided that L1, the manifest's copy in APCu (ADR-0007), stores the index with the rules it was built from.
- Rebuilding the index cost 49 µs per PHP-FPM request with 100 rules. With rebuilding 100 rule objects, it kept SPEC-028's first build above the 0.5 ms target (`benchmarks/results/phase-3-apcu-manifest.md`).
- The index and its rules are written in one entry, at once, and the index is checked when it comes back. Only something other than the package writing the entry could make them disagree.
- The cache store's manifest still never holds the index, so its format does not change.
