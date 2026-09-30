# Benchmark: one store round trip per request (SPEC-027)

SPEC-027 counts a request's matched rules in one call to the counter store, which on Redis is one Lua script (ADR-0026). With 3 matched rules, the Phase 2 exit benchmark made 3 Redis round trips per request; now it makes 1. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-28
- Code:
  - Before: `f8c2e42`, the explicit bindings (`phase-3-bindings.md`), one round trip per matched rule.
  - After, rounds 1 and 2: `9ed38d1`, SPEC-027 before its review's fixes.
  - After, rounds 3 and 4: `a324fa4`, SPEC-027 as it ships: the review's fixes, and the scripts' SHA-1s written out.
- Machine: as in `phase-2-exit.md` and `phase-3-bindings.md`. A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants. PHP 8.4.19 (NTS) with OPcache, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

## The middleware benchmark
`MiddlewareBench`, with 100 rules of which 3 match. Before and after ran alternately, four times, on the same machine, because the shared hardware drifts. Microseconds per request, 200 requests per case.

| Store | Manifest | Round | p50, before | p50, after | p95, before | p95, after |
|---|---|---:|---:|---:|---:|---:|
| local Redis | in memory (Octane) | 1 | 647 | 428 | 1,177 | 902 |
| | | 2 | 709 | 495 | 1,272 | 805 |
| | | 3 | 678 | 502 | 1,509 | 1,034 |
| | | 4 | 651 | 504 | 1,222 | 878 |
| local Redis | read per request (PHP-FPM) | 1 | 1,386 | 1,068 | 2,455 | 1,756 |
| | | 2 | 1,416 | 1,470 | 2,563 | 2,254 |
| | | 3 | 1,688 | 1,421 | 2,629 | 2,224 |
| | | 4 | 1,524 | 1,351 | 2,713 | 2,161 |
| array | in memory (Octane) | 1 | 178 | 169 | 298 | 292 |
| | | 2 | 171 | 168 | 310 | 296 |
| | | 3 | 165 | 174 | 337 | 281 |
| | | 4 | 171 | 175 | 312 | 282 |
| array | read per request (PHP-FPM) | 1 | 552 | 529 | 962 | 1,013 |
| | | 2 | 515 | 528 | 976 | 1,049 |
| | | 3 | 543 | 563 | 997 | 996 |
| | | 4 | 528 | 555 | 987 | 971 |

- **On local Redis, with the manifest in memory,** a request is 150 to 220 µs faster at p50, and 275 to 475 µs faster at p95: the two round trips it no longer makes. p95 is now 0.8 to 1.0 ms, where the Phase 2 exit measured 1.25 ms.
- **On local Redis, with the manifest read per request,** p95 is 300 to 700 µs lower. p50 is 170 to 320 µs lower in three rounds, and 54 µs higher in round 2: this shared Docker network's round trips vary by that much.
- **On the array store,** where no network is involved, p50 moves by −23 to +27 µs, within this machine's noise. Planning the rules in PHP, then deciding after the one call, costs nothing measurable.
- **The scripts' SHA-1s:** hashing the 2.6 KB batch script cost 4.99 µs per request, measured alone over 100,000 hashes. Rounds 3 and 4 include that saving.
- **One round trip:** `tests/Drivers/RedisBatchTest.php` counts the commands Laravel's Redis connection sends: one `EVALSHA` per request, with 1, 3 or 10 matched rules (SPEC-027 AC-1).
- **Raw data:** round 4's runs are in `phase-3-one-round-trip-before.xml` and `phase-3-one-round-trip-after.xml`, next to this file. The other rounds' numbers are from their console output.

## Against the restated target
The Phase 3 exit criteria (`docs/roadmap.md`):
- The Redis driver makes one round trip per request, outside Redis Cluster, which a test counts: met.
- The package's own time, measured on the array store, is under 0.5 ms at p95:
  - With the manifest in memory, p95 is 0.28 to 0.30 ms: met.
  - With the manifest read per request, p95 is still about 1 ms. The manifest's APCu cache on PHP-FPM (SPEC-028) is what is left for that case.
