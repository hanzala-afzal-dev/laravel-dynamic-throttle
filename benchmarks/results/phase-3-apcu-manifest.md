# Benchmark: the manifest's APCu cache on PHP-FPM (SPEC-028)

SPEC-028 keeps the manifest a server read in APCu (ADR-0007's L1), so a PHP-FPM request builds it from there instead of reading and decoding it from the cache store. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-01
- Code:
  - Before: `3674e72`, `main` with SPEC-027: no L1, so a PHP-FPM request reads and decodes the manifest.
  - After: `6019bdb`, SPEC-028's L1.
  - Both run the same `MiddlewareBench`, with its new case "PHP-FPM with APCu": `refresh_seconds` 2, and the process's memory emptied before each request, as PHP-FPM empties it.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.80 GHz and 15 GiB of RAM, shared with other tenants. It is another host than the earlier results', so only this file's numbers compare with each other. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28 with its default serializer, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

## The middleware benchmark
`MiddlewareBench`, with 100 rules of which 3 match. Before and after ran alternately, twice, on the same machine. Microseconds per request, 200 requests per case.

| Store | Manifest | Round | p50, before | p50, after | p95, before | p95, after |
|---|---|---:|---:|---:|---:|---:|
| array | PHP-FPM with APCu | 1 | 535 | 371 | 1,034 | 651 |
| | | 2 | 540 | 375 | 1,010 | 697 |
| array | in memory (Octane) | 1 | 174 | 178 | 329 | 330 |
| | | 2 | 172 | 180 | 326 | 342 |
| array | read per request (PHP-FPM without APCu) | 1 | 540 | 553 | 992 | 1,044 |
| | | 2 | 541 | 535 | 1,002 | 941 |
| local Redis | PHP-FPM with APCu | 1 | 1,176 | 749 | 2,058 | 1,248 |
| | | 2 | 1,209 | 756 | 1,997 | 1,185 |
| local Redis | in memory (Octane) | 1 | 492 | 514 | 817 | 774 |
| | | 2 | 510 | 532 | 782 | 883 |
| local Redis | read per request (PHP-FPM without APCu) | 1 | 1,158 | 1,197 | 1,899 | 1,951 |
| | | 2 | 1,262 | 1,289 | 1,955 | 2,126 |

- **PHP-FPM with APCu, on the array store,** a request is 165 µs faster at p50, and 315 to 385 µs faster at p95.
- **On local Redis,** it saves the manifest's round trip too: 425 to 455 µs at p50, and 810 µs at p95.
- **The other cases** run the same code before and after, and move within this machine's noise.
- **Raw data:** round 2's runs are in `phase-3-apcu-manifest-before.xml` and `phase-3-apcu-manifest-after.xml`, next to this file.

## Where L1's time goes
Measured alone, in one process, with the benchmark's 100 rules (microseconds, 3,000 samples):

| Step | p50 | p95 |
|---|---:|---:|
| Decoding the manifest's JSON, which L1 replaces | 471 | 863 |
| Fetching the snapshot from APCu: 100 rule objects, 36.6 KB | 109 | 169 |
| Rebuilding the rule index from the rules (ADR-0023) | 49 | 75 |
| Both, as a request does | 167 | 351 |
| Freeing a built manifest | 7 | 17 |

- Unserializing the rules costs twice what the same data costs as plain arrays (50 µs). Enums are not the reason: PHP writes each case once, then refers to it.
- An inlined index loop builds the index in 33 µs instead of 49. The nested arrays themselves cost about 30 µs per 100 rules.
- **The spec's fallback, prototyped:** an entry holding the index and each rule serialized on its own, of which a request unserializes only its candidates, 7 here, costs 30 µs at p50 and 59 µs at p95.

## Against the target
The Phase 3 exit criteria (`docs/roadmap.md`): the package's own time, measured on the array store, under 0.5 ms at p95 on PHP-FPM with APCu.
- With SPEC-028, p95 is 651 to 697 µs: **missed**. It was 1,010 to 1,034 µs before.
- The manifest in memory, as on Octane, sets the floor: 326 to 342 µs at p95 on this host. L1 adds about 195 µs at p50 and 320 to 355 µs at p95, rebuilding 100 rule objects and their index on every request.
