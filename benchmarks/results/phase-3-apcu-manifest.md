# Benchmark: the manifest's APCu cache on PHP-FPM (SPEC-028)

SPEC-028 keeps the manifest a server read in APCu (ADR-0007's L1), so a PHP-FPM request builds it from there instead of reading and decoding it from the cache store. Its first build missed the Phase 3 target, so the maintainer chose the spec's fallback: L1 stores the index with the rules, and a request builds only the rules it matches. This file records both. ADR-0016 publishes numbers only with the hardware stated.

## As shipped: only the matched rules are built
- Date: 2026-10-01
- Code, run alternately, twice:
  - `main`: `3674e72`, `main` with SPEC-027: no L1.
  - First build: `6019bdb`, L1 holding the rules as objects, rebuilt in full with their index on every request.
  - As shipped: `5c763f3`, L1 holding plain values: the index, each rule's JSON, the exemptions. A request builds only the rules it matches.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants, as in `phase-3-one-round-trip.md`. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28 with its default serializer, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

`MiddlewareBench`, with 100 rules of which 3 match, and its case "PHP-FPM with APCu": `refresh_seconds` 2, and the process's memory emptied before each request, as PHP-FPM empties it. Microseconds per request, 200 requests per case.

| Store | Manifest | Round | p50, `main` | p50, first build | p50, as shipped | p95, `main` | p95, first build | p95, as shipped |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| array | PHP-FPM with APCu | 1 | 500 | 342 | 235 | 922 | 672 | 397 |
| | | 2 | 488 | 314 | 241 | 895 | 506 | 419 |
| array | in memory (Octane) | 1 | 159 | 170 | 167 | 271 | 267 | 290 |
| | | 2 | 169 | 168 | 164 | 294 | 288 | 255 |
| array | read per request (PHP-FPM without APCu) | 1 | 488 | 482 | 494 | 861 | 946 | 956 |
| | | 2 | 494 | 483 | 483 | 975 | 918 | 832 |
| local Redis | PHP-FPM with APCu | 1 | 1,060 | 648 | 564 | 1,857 | 1,055 | 924 |
| | | 2 | 987 | 625 | 523 | 1,646 | 1,013 | 855 |
| local Redis | in memory (Octane) | 1 | 353 | 360 | 401 | 708 | 710 | 711 |
| | | 2 | 359 | 373 | 360 | 715 | 714 | 751 |
| local Redis | read per request (PHP-FPM without APCu) | 1 | 978 | 1,003 | 950 | 1,754 | 1,844 | 1,578 |
| | | 2 | 1,065 | 993 | 1,040 | 1,682 | 1,559 | 1,804 |

- **PHP-FPM with APCu, on the array store,** a request is 245 to 265 µs faster at p50 than on `main`, and 475 to 525 µs faster at p95. It costs 70 to 80 µs more than the manifest in memory at p50.
- **On local Redis,** it also saves the manifest's round trip: 465 to 495 µs at p50, and 790 to 935 µs at p95.
- **Against the first build,** building only the matched rules saves 70 to 110 µs at p50, and 85 to 275 µs at p95.
- **The other cases** run the same code in all three, apart from the matcher reading rules through `Manifest::rule()`, and move within this machine's noise.
- **Raw data:** round 2's runs of `main` and of the shipped code are in `phase-3-apcu-manifest-before.xml` and `phase-3-apcu-manifest-after.xml`, next to this file. The other runs' numbers are from their console output.

### Against the target
The Phase 3 exit criteria (`docs/roadmap.md`): the package's own time, measured on the array store, under 0.5 ms at p95 on PHP-FPM with APCu.
- As shipped, p95 is 397 to 419 µs: **met**. It is 895 to 922 µs on `main`.
- With the manifest in memory, as on Octane, p95 is 255 to 294 µs: met, as SPEC-027 measured.

## The first build, which missed the target
- Date: 2026-10-01, before the fallback.
- Code: before, `3674e72` (`main`); after, `6019bdb`, the first build. They ran alternately, twice.
- Machine: as above, but on another host: an Intel Xeon at 2.80 GHz. Its numbers compare only with each other.

### The middleware benchmark
`MiddlewareBench`, as above. Microseconds per request, 200 requests per case.

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
- **Raw data:** these numbers are from the runs' console output.

### Where the first build's time went
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

### Against the target
- With the first build, p95 was 651 to 697 µs: **missed**. It was 1,010 to 1,034 µs before.
- The manifest in memory, as on Octane, set the floor: 326 to 342 µs at p95 on that host. The first build added about 195 µs at p50 and 320 to 355 µs at p95, rebuilding 100 rule objects and their index on every request. The maintainer then chose the spec's fallback, measured above.
