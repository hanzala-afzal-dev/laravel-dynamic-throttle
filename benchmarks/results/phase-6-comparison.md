# Benchmark: a request with no limiter, Laravel's throttle, and Dynamic Throttle

Issue #101 asks what the middleware costs against what users already know: a route with no limiter, and Laravel's own `throttle` on the same Redis. The user-facing summary is `docs/guide/performance.md`. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-19
- Code: `2b4d796` on branch `beta/performance-page`, `main` with `ComparisonBench`.
- Command: `vendor/bin/phpbench run benchmarks/Http/ComparisonBench.php --report=engine --dump-file=…`, then `php benchmarks/percentiles.php <dump>`, run twice. `php -d apc.enable_cli=1 benchmarks/round-trips.php` counted the Redis calls and database queries.
  - One request goes through the whole HTTP kernel of a Testbench app, with its default global middleware, to a route that returns `ok`. The kernel's own time is the same in every case, so the differences are what each limiter adds.
  - Every limiter counts on the local Redis, through phpredis: Laravel's `throttle` (`ThrottleRequests`) through the redis cache store, `throttleWithRedis()` (`ThrottleRequestsWithRedis`) on a Redis connection, and Dynamic Throttle with its Redis driver.
  - Every limit is 1,000,000 a minute, so no request is rejected.
  - Dynamic Throttle has 1 rule, which matches the request, or `MiddlewareBench`'s 100 rules, 3 of which match. Telemetry is on, as by default.
  - "Octane" keeps the manifest in memory. "PHP-FPM with APCu" empties the process's memory before each request and reads the manifest from APCu (`apc.enable_cli=1`), with `refresh_seconds` 2. "PHP-FPM without APCu" reads it from Redis on every request.
  - `benchRequest` stops at the response. `benchRequestAndTerminate` adds `terminate()`, where Dynamic Throttle writes its telemetry: time the worker stays busy, but the client does not wait for on PHP-FPM.
  - Each iteration is one request, in a process of its own, after 20 warm-up requests in that process: 200 iterations per case and run.
- Raw data: not committed (ADR-0027).

## Machine

- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants.
- PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, phpredis 6.3.0.
- Laravel 13.33.0, PHPBench 1.7.0.
- Redis 7.4.11 in Docker (`docker compose`), over the Docker bridge network.

## Redis calls and database queries per request

| Case | Redis, before the response | Redis, after it | Database |
|---|---:|---:|---:|
| No limiter | 0 | 0 | 0 |
| Laravel's `throttle` | 5 | 0 | 0 |
| Laravel's `throttle`, with `throttleWithRedis()` | 2 | 0 | 0 |
| Dynamic Throttle, manifest in memory (Octane), 1 or 100 rules | 1 | 1 | 0 |
| Dynamic Throttle, PHP-FPM with APCu, 1 or 100 rules | 1 | 1 | 0 |
| Dynamic Throttle, PHP-FPM without APCu, 1 or 100 rules | 2 | 1 | 0 |

## Until the response (`benchRequest`)

Microseconds per request, from two runs of 200 requests per case:

| Case | p50, run 1 | p50, run 2 | p95, run 1 | p95, run 2 | p99, run 2 |
|---|---:|---:|---:|---:|---:|
| No limiter | 182 | 190 | 321 | 303 | 375 |
| Laravel's `throttle` | 1,218 | 1,157 | 1,918 | 2,246 | 3,312 |
| Laravel's `throttle`, with `throttleWithRedis()` | 735 | 800 | 1,218 | 1,409 | 2,270 |
| Dynamic Throttle, 1 rule, Octane | 924 | 952 | 1,333 | 1,482 | 2,013 |
| Dynamic Throttle, 1 rule, PHP-FPM with APCu | 989 | 976 | 1,460 | 1,451 | 3,113 |
| Dynamic Throttle, 1 rule, PHP-FPM without APCu | 1,244 | 1,091 | 1,864 | 1,925 | 2,695 |
| Dynamic Throttle, 100 rules, Octane | 1,039 | 1,032 | 1,574 | 1,552 | 1,842 |
| Dynamic Throttle, 100 rules, PHP-FPM with APCu | 1,130 | 1,173 | 1,567 | 1,654 | 2,460 |
| Dynamic Throttle, 100 rules, PHP-FPM without APCu | 1,821 | 1,770 | 2,830 | 2,501 | 4,102 |

What each limiter adds at p50, the case's p50 less no limiter's, in each run:
- Laravel's `throttle`: 1,036 and 967 µs.
- `throttleWithRedis()`: 553 and 610 µs.
- Dynamic Throttle, 1 rule: 742 and 762 µs on Octane, 807 and 786 µs on PHP-FPM with APCu, 1,062 and 901 µs without APCu.
- Dynamic Throttle, 100 rules: 857 and 842 µs on Octane, 948 and 983 µs on PHP-FPM with APCu, 1,639 and 1,580 µs without APCu.

## With `terminate()` (`benchRequestAndTerminate`)

| Case | p50, run 1 | p50, run 2 | p95, run 1 | p95, run 2 |
|---|---:|---:|---:|---:|
| No limiter | 267 | 263 | 489 | 494 |
| Laravel's `throttle` | 1,310 | 1,337 | 2,237 | 2,297 |
| Laravel's `throttle`, with `throttleWithRedis()` | 942 | 888 | 1,695 | 1,554 |
| Dynamic Throttle, 1 rule, Octane | 1,440 | 1,555 | 2,231 | 2,300 |
| Dynamic Throttle, 1 rule, PHP-FPM with APCu | 1,587 | 1,499 | 2,229 | 2,089 |
| Dynamic Throttle, 1 rule, PHP-FPM without APCu | 1,713 | 1,718 | 2,563 | 2,547 |
| Dynamic Throttle, 100 rules, Octane | 1,577 | 1,543 | 2,470 | 2,405 |
| Dynamic Throttle, 100 rules, PHP-FPM with APCu | 1,697 | 1,675 | 2,713 | 2,485 |
| Dynamic Throttle, 100 rules, PHP-FPM without APCu | 2,314 | 2,316 | 3,364 | 3,431 |

## Reading it

- **Until the response,** Dynamic Throttle with 1 rule adds 0.75 to 0.8 ms at p50 on Octane or PHP-FPM with APCu: less than Laravel's `throttle`, 1.0 ms, and more than `throttleWithRedis()`, 0.55 to 0.6 ms. Its one Redis call runs a longer script, and it matches rules and hashes identities first.
- **Without APCu,** PHP-FPM reads the manifest from Redis on every request: 0.9 to 1.06 ms with 1 rule, and 1.6 ms with 100 rules, whose manifest it also decodes.
- **After the response,** the telemetry write keeps the worker 0.5 to 0.6 ms longer, against about 0.1 ms for the other cases' `terminate()`.
- **Redis here is local,** about 0.15 ms per round trip over the Docker bridge. On a Redis across a network, each call costs more: 5 before the response for Laravel's `throttle`, 2 for `throttleWithRedis()`, and 1 for Dynamic Throttle, 2 on PHP-FPM without APCu.
- **The slowest requests:** in 7 of the 36 case-runs, the single slowest of the 200 requests took 18 to 59 ms, all in Dynamic Throttle's cases. p99 stays under 4.9 ms in every case. These runs don't show why. The test Redis saving to disk every minute, which this write load triggers, is one candidate.
