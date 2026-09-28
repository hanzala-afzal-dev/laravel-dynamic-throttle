# Benchmark: explicit container bindings on the request path

The Phase 2 exit review (`phase-2-exit.md`) found that Laravel's container built part of the request path by reflection, on every request. This `perf:` PR binds those services explicitly, and builds the manifest compiler only when a missing manifest is rebuilt. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-28
- Code:
  - Before: `fc15fba`, the Phase 2 exit.
  - After: `b337c4f` on branch `phase-3/perf-bindings`.
- What changed:
  - The middleware, the identity resolver and the store factory get explicit bindings. The middleware is built twice per request, for `handle()` and for `terminate()`.
  - The manifest repository gets a lazy rebuilder. The compiler is built only when a missing manifest is rebuilt, after the response.
- Machine: as in `phase-2-exit.md`. A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants. PHP 8.4.19 (NTS) with OPcache, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

## The container, on its own
Throwaway scripts, not kept, timed each build 3,000 times in a booted Testbench app, and give the median. They ran with `php -d opcache.enable_cli=1 -d pcov.enabled=0`, as PHPBench runs.

| Build | Before (µs) | After (µs) | Times per request |
|---|---:|---:|---:|
| The middleware | 9.1 | 3.9 | 2 |
| The identity resolver | 19.0 | 11.1 | 1 |
| The store factory | 14.5 | 8.9 | 1 |
| The manifest repository, compiler included | 25.5 | 13.5 | 1 |

That is about 36 µs less per request.

## The middleware benchmark
`MiddlewareBench`, with 100 rules of which 3 match. Before and after ran alternately, twice, on the same machine within 25 minutes, because the shared hardware drifts. The benchmark builds the middleware once per request, so it shows one of its two builds. Microseconds per request, 200 requests per case:

| Store | Manifest | p50, before | p50, after | p95, before | p95, after |
|---|---|---:|---:|---:|---:|
| array | in memory (Octane) | 214, 216 | 180, 186 | 359, 394 | 324, 337 |
| array | read per request (PHP-FPM) | 585, 600 | 504, 528 | 1,024, 1,062 | 948, 991 |
| local Redis | in memory (Octane) | 791, 849 | 672, 658 | 1,475, 1,502 | 1,230, 1,491 |
| local Redis | read per request (PHP-FPM) | 1,622, 1,862 | 1,435, 1,595 | 2,637, 3,177 | 2,444, 2,811 |

- **On the array store,** where no network is involved, the package's own time drops by about 30 µs at p50 with the manifest in memory, and by about 75 µs with it read per request. The manifest read path also builds the manifest repository, so it gains the most.
- **On local Redis** the gain is the same, but it is buried in the network's noise: that shared Docker network's round trips vary by more than the saving.
- **Raw data:** round 2's runs are in `phase-3-bindings-before.xml` and `phase-3-bindings-after.xml`, next to this file. Round 1's numbers are from its console output.

## Against the restated target
The Phase 3 target is under 0.5 ms of the package's own time at p95, measured on the array store, with one store round trip per request (`docs/roadmap.md`):
- With the manifest in memory, p95 is 0.32 to 0.34 ms: met.
- With the manifest read per request, p95 is 0.95 to 0.99 ms. The manifest's APCu cache on PHP-FPM (SPEC-028) is what is left for that case.
