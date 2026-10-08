# Benchmark: telemetry, on the response path and after it (SPEC-015)

SPEC-015 records what each rule did with a request. `handle()` builds the records and keeps them in a request attribute; `terminate()` writes them to the counter store after the response is sent. Decision 12 holds the response path to ADR-0013's 10% budget, measured with `MiddlewareBench` with telemetry on and off, and measures the write after the response on its own, with `TelemetryBench`. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-04
- Code, run alternately, twice, each in the same clone of the repository:
  - Before: `70faa0c`, SPEC-014's head, which PR #19 holds.
  - After: `5c4875a`, SPEC-015 with its review's fixes, on the branch `phase-3/spec-015`, before SPEC-015 was cut into three PRs (ADR-0027). Its `src/Http` and `src/Engine` are the request path that SPEC-015's second PR carries.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants, as in `phase-3-explain.md`. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 and Memcached 1.6.45 in Docker.

## The response path: `MiddlewareBench`

100 rules, of which 3 match, and every one allows the request. Microseconds per request, 200 requests per case. The before code has no telemetry, so its "on" and "off" runs are two samples of the same code: they show this host's noise.

| Store | Manifest | Round | p50 before | p50 after, off | p50 after, on | p95 before | p95 after, off | p95 after, on |
|---|---|---:|---:|---:|---:|---:|---:|---:|
| array | in memory (Octane) | 1 | 160 | 166 | 170 | 273 | 286 | 284 |
| | | 2 | 164 | 162 | 169 | 261 | 254 | 272 |
| array | PHP-FPM with APCu | 1 | 227 | 226 | 243 | 391 | 380 | 445 |
| | | 2 | 232 | 224 | 231 | 404 | 418 | 410 |
| array | read per request (PHP-FPM without APCu) | 1 | 488 | 483 | 478 | 909 | 961 | 787 |
| | | 2 | 472 | 491 | 483 | 872 | 878 | 876 |
| local Redis | in memory (Octane) | 1 | 530 | 550 | 599 | 1,061 | 1,039 | 962 |
| | | 2 | 473 | 517 | 573 | 890 | 1,001 | 934 |
| local Redis | PHP-FPM with APCu | 1 | 746 | 699 | 795 | 1,132 | 1,161 | 1,271 |
| | | 2 | 705 | 735 | 751 | 1,185 | 1,154 | 1,103 |
| local Redis | read per request (PHP-FPM without APCu) | 1 | 1,458 | 1,444 | 1,455 | 2,070 | 2,131 | 2,171 |
| | | 2 | 1,492 | 1,402 | 1,450 | 2,251 | 2,327 | 2,318 |

- **On the array store, telemetry adds about 5 µs at p50:** +4 and +7 µs with the manifest in memory, +17 and +7 µs on PHP-FPM with APCu, and nothing measurable when the manifest is read per request, where the noise is larger. The before code's own two runs differ by up to 10 µs at p50, so this is at the edge of what this host can measure. Even the largest difference, +17 µs on 226, is 7.5%: within decision 12's 10% budget.
- **On local Redis,** the round trip to Redis in Docker dominates, and the runs move by up to 125 µs at p50 either way, the before code's too.
- **Raw data:** PHPBench's XML dumps are not committed (ADR-0027). Each run swapped in its code's `src` only, in a clone on `5c4875a`. The numbers are from the runs' output.

### Against the target
The Phase 3 exit criteria (`docs/roadmap.md`): the package's own time, on the array store, under 0.5 ms at p95 on Octane and on PHP-FPM with APCu.
- PHP-FPM with APCu, telemetry on: 410 to 445 µs at p95. **Still met.**
- In memory, as on Octane, telemetry on: 272 to 284 µs at p95. **Still met.**

## After the response: `TelemetryBench`

`terminate()`'s write for `MiddlewareBench`'s 3 matching rules, after code only. As on a request, the scoped services are built again each time. The response is sent by then, so this is time the worker spends, not time the client waits. Microseconds per request, 200 requests per case.

| Store | The request | p50 | p95 | p99 |
|---|---|---:|---:|---:|
| array (the cache driver, in memory) | 3 rules count it | 66 | 104 | 173 |
| | 1 of 3 would block it | 74 | 116 | 170 |
| local Memcached (the cache driver) | 3 rules count it | 751 | 1,384 | 1,857 |
| | 1 of 3 would block it | 968 | 1,720 | 3,900 |
| local Redis (the Redis driver) | 3 rules count it | 183 | 453 | 823 |
| | 1 of 3 would block it | 202 | 634 | 1,039 |

- **Redis** writes a request's numbers in one script call, so about one round trip: the would-block adds its offenders and distinct clients to the same call.
- **The cache driver** makes an `add()` and an `increment()` per rule and metric: 6 round trips for 3 counting rules, 8 with a would-block. On Memcached in Docker that is 0.75 to 1 ms of worker time per request.
- **The array store** shows the package's own cost: building the scoped buffer, the cooldown check and the sampler, about 70 µs.
- **Raw data:** PHPBench's XML dump is not committed (ADR-0027).

### For the maintainer
The roadmap's fallback lowers the default sample rate before 0.1.0 if telemetry costs too much. On the response path it costs about 5 µs, within budget. After the response, Redis costs about one round trip per request, which the 1 default suits. On Memcached, a busy app pays about 1 ms of worker time per request at the default: the README tells it to lower `telemetry.sample_rate`, and a lower default for every driver is your call.
