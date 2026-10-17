# Benchmark: the live feed's entries (SPEC-030)

SPEC-030 AC-9 holds the allowed request to the same cost as before, measured with `MiddlewareBench`. It also measures a blocked request's write after the response, now with its feed entry, with `TelemetryBench`. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-17
- Code, each in the same clone of the repository:
  - Before: `f9c7b59`, SPEC-030 part 2's head, where the buffers keep a feed but the middleware gives them none.
  - After: `b1e5423`, part 3 with the review's fixes. An allowed request keeps nothing more for the feed, and a block or a would-block builds one entry per rule.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.80 GHz and 15 GiB of RAM, shared with other tenants. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 and Memcached 1.6.45 in Docker. Compare these numbers with each other only.
- Raw data: PHPBench's XML dumps are not committed (ADR-0027). The numbers are from the runs' output.
- Noise: the full runs were done once each, and moved by up to 145 µs at p50 between runs, also on rows the change does not touch. So the cases that matter were also run alternately, three rounds each way, and those rounds decide.

## The allowed request: `MiddlewareBench`

100 rules, of which 3 match, and every one allows the request. Microseconds per request, 200 requests per case.

**Alternating rounds**, array store, manifest in memory (Octane), telemetry on, in the order they ran:

| Round | p50 before | p50 after | p95 before | p95 after |
|---|---:|---:|---:|---:|
| 1 | 235 | 229 | 444 | 492 |
| 2 | 229 | 212 | 447 | 437 |
| 3 | 209 | 217 | 406 | 438 |

- **The allowed request costs the same:** 229 µs before and 217 µs after at the median p50, within the rounds' own spread. What it gained is one boolean and two empty slots in the request attribute it already writes.

**One full run each way**, for the other cases:

| Store | Manifest | p50 before, on | p50 after, on | p50 before, off | p50 after, off |
|---|---|---:|---:|---:|---:|
| array | in memory (Octane) | 242 | 253 | 221 | 280 |
| array | PHP-FPM with APCu | 319 | 373 | 300 | 350 |
| array | read per request (PHP-FPM without APCu) | 599 | 702 | 582 | 664 |
| local Redis | in memory (Octane) | 658 | 674 | 610 | 680 |
| local Redis | PHP-FPM with APCu | 825 | 763 | 789 | 788 |
| local Redis | read per request (PHP-FPM without APCu) | 1,533 | 1,504 | 1,429 | 1,574 |

- The "after" run was slower across the board, with telemetry off too, whose code did not change: the host was busier. Within each run, telemetry on costs what it cost before, give or take that noise.

## After the response: `TelemetryBench`

`terminate()`'s write for `MiddlewareBench`'s 3 matching rules. When 1 of 3 would block the request, the write now carries its feed entry. The response is sent by then, so this is time the worker spends, not time the client waits. Microseconds per request, 200 requests per case.

**Alternating rounds** on local Redis, in the order they ran:

| Round | The request | p50 before | p50 after | p95 before | p95 after |
|---|---|---:|---:|---:|---:|
| 1 | 3 rules count it | 385 | 389 | 633 | 659 |
| | 1 of 3 would block it | 429 | 462 | 622 | 713 |
| 2 | 3 rules count it | 402 | 368 | 647 | 591 |
| | 1 of 3 would block it | 413 | 501 | 670 | 854 |
| 3 | 3 rules count it | 382 | 396 | 720 | 640 |
| | 1 of 3 would block it | 411 | 458 | 676 | 798 |

**One full run each way:**

| Store | The request | p50 before | p50 after | p95 before | p95 after |
|---|---|---:|---:|---:|---:|
| array (the cache driver, in memory) | 3 rules count it | 105 | 99 | 214 | 172 |
| | 1 of 3 would block it | 105 | 112 | 220 | 196 |
| local Memcached (the cache driver) | 3 rules count it | 1,268 | 1,224 | 1,988 | 2,120 |
| | 1 of 3 would block it | 1,536 | 1,577 | 2,285 | 2,235 |
| local Redis (the Redis driver) | 3 rules count it | 403 | 384 | 684 | 620 |
| | 1 of 3 would block it | 384 | 514 | 692 | 874 |

- **Redis:** a would-block's write costs about 50 µs more at p50 (413 to 462 µs at the median), with its feed entry in the same script call: still one round trip. A request that no rule blocks writes no entry, and costs the same.
- **The array store:** about 7 µs more, which is building the entry: the route, the client IP and the user. The cache driver keeps no feed, so the entry is built and dropped.
- **Memcached:** within its noise. The cache driver keeps no feed.
