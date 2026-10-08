# Benchmark: the telemetry the health checks read (SPEC-016)

SPEC-016's checks of what the rules saw read more telemetry. `handle()` builds a few more records: the guests a user rule counted, and the rules skipped or ignored. `terminate()` writes them, and on Redis also the hour's traffic: each request's client, in a sorted set, and the hour's request count. AC-22 holds the response path within 10% of SPEC-015's at p50, measured with `MiddlewareBench`, and measures the write after the response with `TelemetryBench`. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-07
- Code, run alternately, once, each in the same clone of the repository:
  - Before: `898ad6c`, SPEC-015's head, the last of its three PRs.
  - After: `553aec5`, SPEC-016's telemetry with the review's fix to the traffic's client.
- Only one round ran: a second would have taken another 40 minutes on this host. The "off" rows of each run, which the change does not touch, give the noise.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants. It ran slower than in `phase-3-telemetry.md`, so compare these numbers with each other only. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 and Memcached 1.6.45 in Docker.
- Raw data: PHPBench's XML dumps are not committed (ADR-0027). The numbers are from the runs' output.

## The response path: `MiddlewareBench`

100 rules, of which 3 match, and every one allows the request. Microseconds per request, 200 requests per case.

| Store | Manifest | p50 before, on | p50 after, on | p50 before, off | p50 after, off | p95 before, on | p95 after, on |
|---|---|---:|---:|---:|---:|---:|---:|
| array | in memory (Octane) | 172 | 174 | 159 | 170 | 322 | 307 |
| array | PHP-FPM with APCu | 226 | 237 | 239 | 257 | 422 | 420 |
| array | read per request (PHP-FPM without APCu) | 509 | 509 | 514 | 520 | 953 | 996 |
| local Redis | in memory (Octane) | 561 | 635 | 647 | 635 | 983 | 1,101 |
| local Redis | PHP-FPM with APCu | 726 | 802 | 761 | 778 | 1,231 | 1,228 |
| local Redis | read per request (PHP-FPM without APCu) | 1,580 | 1,522 | 1,540 | 1,640 | 2,447 | 2,317 |

- **On the array store, with telemetry on, the response path costs at most 11 µs more at p50:** +2 µs with the manifest in memory, +11 µs (4.9%) on PHP-FPM with APCu, and nothing when the manifest is read per request. That is within AC-22's 10%.
- **The noise is about as large:** with telemetry off, which the change does not touch, the same code moved by +11 and +18 µs at p50.
- **On local Redis,** the round trip to Redis in Docker dominates, and the runs move by up to 100 µs at p50 either way, with telemetry off too.

## After the response: `TelemetryBench`

`terminate()`'s write for `MiddlewareBench`'s 3 matching rules. As on a request, the scoped services are built again each time. The response is sent by then, so this is time the worker spends, not time the client waits. Microseconds per request, 200 requests per case.

| Store | The request | p50 before | p50 after | p95 before | p95 after |
|---|---|---:|---:|---:|---:|
| array (the cache driver, in memory) | 3 rules count it | 66 | 81 | 123 | 129 |
| | 1 of 3 would block it | 78 | 93 | 134 | 160 |
| local Memcached (the cache driver) | 3 rules count it | 720 | 832 | 1,351 | 1,353 |
| | 1 of 3 would block it | 969 | 1,085 | 1,845 | 2,264 |
| local Redis (the Redis driver) | 3 rules count it | 207 | 261 | 627 | 816 |
| | 1 of 3 would block it | 226 | 271 | 648 | 708 |

- **Redis:** about 50 µs more per request. The script call writes the hour's client and request count too, so it stays one round trip.
- **The array store:** 15 µs more, mostly the client's identity hash, which `terminate()` works out on every driver though only Redis keeps the traffic.
- **Memcached:** about 110 µs more at p50, though the cache driver adds no key for these requests, and keeps no client. The difference is network noise: the round trips dominate, and the p95 of the first row did not move.
