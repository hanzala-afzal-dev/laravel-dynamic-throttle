# Benchmark: the middleware (Phase 2 exit)

SPEC-010 AC-20 and the Phase 2 exit criterion ask what the middleware adds to a request at the 95th percentile, with 100 active rules of which 3 match, on local Redis. The roadmap's target is 1 ms, as a hypothesis to adjust with data. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-25
- Code: the SPEC-010 implementation after its review, `5a0fc86` on branch `phase-2/rules-and-middleware`.
- Command: `vendor/bin/phpbench run benchmarks/Http/MiddlewareBench.php --report=engine --dump-file=…`, then `php benchmarks/percentiles.php <dump>`.
  - One request is `handle()` on the middleware, with a `$next` that returns at once. It includes building the middleware and the per-request (scoped) services from Laravel's container, as each request does on Octane and on PHP-FPM.
  - The request matches 3 of 100 rules: `*` `api/*`, `GET` `api/users/*` and `*` `*/export`. Each counts it, by IP, and allows it.
  - "Manifest in memory" is Octane or a queue worker within `manifest.refresh_seconds`. "Manifest read per request" is PHP-FPM, which starts every request with nothing in memory: it reads the 24.8 KB manifest from the cache store and decodes it.
  - On the Redis store, the counters and the manifest both live in local Redis, through Laravel's `redis` cache driver and phpredis. The connection is reused, as it is when the app already talked to Redis in the request, or uses persistent connections.
  - Each iteration is one request, in a process of its own, after 20 warm-up requests in that process: 200 iterations per case. So the iterations are the distribution of single requests.
- Raw data: `phase-2-middleware.xml`, next to this file, holds run 2. Run 1's numbers are from its console output.

## Machine
- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.80 GHz and 15 GiB of RAM. The hardware is shared with other tenants.
- Linux 6.18.44, Ubuntu 24.04.4.
- PHP 8.4.19 (NTS) with OPcache and no JIT, Xdebug not loaded, PCOV loaded but disabled.
- Laravel 13.33.0, PHPBench 1.7.0.
- Redis 7.4.11 in Docker (`docker compose`), reached over the Docker bridge network.
- On this machine, the same day: a Redis round trip (`PING`) took 143 µs and one `RedisLimiterStore` attempt 184 µs. The Phase 1 baseline measured 88 µs and 128 µs on the same kind of machine (`phase-1-baseline.md`), so its Redis was about 1.5 times faster.

## Results
Microseconds per request, from two runs of 200 requests per case:

| Store | Manifest | p50, run 1 | p95, run 1 | p50, run 2 | p95, run 2 | p99, run 2 |
|---|---|---:|---:|---:|---:|---:|
| array | in memory | 187 | 304 | 189 | 336 | 450 |
| local Redis | in memory | 766 | 1,192 | 830 | 1,196 | 1,569 |
| array | read per request | 541 | 699 | 550 | 831 | 1,142 |
| local Redis | read per request | 1,418 | 2,132 | 1,489 | 2,311 | 3,113 |

**Against the 1 ms target:** met on the array store in both cases, and missed on local Redis: 1.2 ms with the manifest in memory, and 2.1 to 2.3 ms with the manifest read per request.

Where one request goes, timed part by part with `hrtime()` by a throwaway script, which is not kept (medians of 300 requests on the idle machine, run as `php -d opcache.enable_cli=1 -d pcov.enabled=0`, as PHPBench runs):

| Part | Array store | Local Redis |
|---|---:|---:|
| Laravel's container building the middleware, the manifest repository, the resolver and the evaluator, by reflection | 89 µs | 104 µs |
| The manifest from memory, and matching | 13 µs | 22 µs |
| The manifest read from the cache and decoded, on PHP-FPM, instead of from memory | 331 µs | 589 µs |
| Three attempts, identities included | 67 µs | 579 µs |

## What this means
- **Redis round trips dominate.** Each matched rule is one round trip, one after another: three rules cost three. Here a round trip is about 140 µs, which is slow for a local Redis. On a faster network the Redis rows shrink by roughly the saved time per round trip, three times over.
- **On PHP-FPM, the manifest costs a third of a millisecond per request, and 0.6 ms from Redis.** It is read and decoded every time, because FPM keeps nothing between requests. That is the case ADR-0007's L1 cache (APCu) is for, and the roadmap decides it with this benchmark.
- **Half of the array store's cost is Laravel's container.** Building the package's services by reflection takes about 90 µs per request. Explicit bindings would save part of it.
- **Options for the Phase 2 exit review.** They are not SPEC-010's, and nothing here changes behavior:
  1. One round trip per request on Redis: one Lua script that counts every matched rule, stops at the first that rejects, and releases the others, atomically. Three round trips become one.
  2. The L1 cache on PHP-FPM (ADR-0007), or the documented recommendation to run Octane for busy apps.
  3. Explicit container bindings for the request path's services.
  4. A target measured against a stated Redis round trip, since the absolute number mostly follows the network: the roadmap calls the 1 ms target a hypothesis to adjust with data.
