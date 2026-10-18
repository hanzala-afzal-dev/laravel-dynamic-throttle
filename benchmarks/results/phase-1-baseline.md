# Benchmark baseline: Phase 1

The counter engine alone: no rules, no HTTP. Recorded for the Phase 1 exit criteria (docs/roadmap.md). ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-24
- Commit: `5a06303` (branch `phase-1/core-engine`)
- Command: `composer bench`. Each subject runs 10 iterations after one warm-up iteration. An iteration is 500 calls for store subjects and 10,000 for pure PHP.
- Raw data: PHPBench's XML dump was removed from the repository on 2026-10-18 (ADR-0027). Git history keeps it: `git log --diff-filter=D -- 'benchmarks/results/*.xml'` finds the commit that removed it.

## Machine
- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz (one thread per core, as the guest reports it) and 15 GiB of RAM. The hardware is shared with other tenants.
- Linux 6.18.44, Ubuntu 24.04.4, Docker 29.3.1.
- PHP 8.4.19 (NTS) with OPcache and no JIT, Xdebug not loaded, PCOV loaded but disabled.
- ext-redis 6.3.0, ext-memcached 3.4.0, Predis 3.6.1, Laravel 13.33.0, PHPBench 1.7.0.
- Redis 7.4.11, Valkey 8.1.10 and Memcached 1.6.45 run under `docker compose` on the same VM. PHP reaches them through ports published on 127.0.0.1, and Docker's userland proxy relays every round trip. A plain round trip (`benchRoundTrip`) costs 80 to 105 µs here.

## How to read the numbers
- `mode` is the most likely time per call, and `rstdev` is the spread between the 10 iterations.
- Three runs of the same code differed by 7.8% per subject at the median, and 17% at worst. On this machine, treat differences under 20% as noise.
- Compare only with runs on similar hardware. The samplers in the XML dump (`nothing`, `md5`, `file_rw`) are a rough speed reference.
- A store call costs its round trips plus the package's own work:
  - The Redis driver makes one round trip per call. An attempt costs 20 to 50 µs more than a PING, for the Lua script and `EVALSHA`, and a release up to 30 µs more. Differences this small are close to the noise.
  - The cache driver makes three round trips for an allowed attempt (`add`, `increment`, `get`), four for a rejected one (plus the undo), and two for a release (`get`, `decrement`).
- In pure PHP, the formula takes 0.2 to 0.3 µs, and reading the Laravel clock about 1.4 µs. A whole attempt on the array store takes 6 to 9 µs.
- These are typical costs per call, not percentiles. The roadmap's Phase 2 target (added p95 below 1 ms, with 100 active rules and 3 matched) needs its own benchmark of the middleware.

## Results

| benchmark | subject | set | revs | its | mem_peak | mode | best | worst | rstdev |
|---|---|---|---:|---:|---:|---:|---:|---:|---:|
| LaravelClockBench | benchMicroseconds |  | 10000 | 10 | 2.849mb | 1.425μs | 1.239μs | 2.067μs | ±17.19% |
| SlidingWindowCounterBench | benchAllowed |  | 10000 | 10 | 1.751mb | 0.218μs | 0.192μs | 0.453μs | ±35.40% |
| SlidingWindowCounterBench | benchRejected |  | 10000 | 10 | 1.751mb | 0.270μs | 0.255μs | 0.274μs | ±2.37% |
| RedisLimiterStoreBench | benchAllowed | redis (phpredis) | 500 | 10 | 1.752mb | 127.693μs | 122.110μs | 146.702μs | ±6.16% |
| RedisLimiterStoreBench | benchAllowed | valkey (phpredis) | 500 | 10 | 1.752mb | 126.044μs | 100.590μs | 136.720μs | ±7.40% |
| RedisLimiterStoreBench | benchAllowed | redis (predis) | 500 | 10 | 1.752mb | 132.237μs | 121.684μs | 211.558μs | ±17.63% |
| RedisLimiterStoreBench | benchAllowed | valkey (predis) | 500 | 10 | 1.752mb | 138.336μs | 123.790μs | 163.968μs | ±8.46% |
| RedisLimiterStoreBench | benchRejected | redis (phpredis) | 500 | 10 | 1.752mb | 125.188μs | 105.958μs | 151.662μs | ±9.61% |
| RedisLimiterStoreBench | benchRejected | valkey (phpredis) | 500 | 10 | 1.752mb | 116.976μs | 108.500μs | 168.656μs | ±14.35% |
| RedisLimiterStoreBench | benchRejected | redis (predis) | 500 | 10 | 1.752mb | 140.083μs | 126.486μs | 167.844μs | ±7.99% |
| RedisLimiterStoreBench | benchRejected | valkey (predis) | 500 | 10 | 1.752mb | 122.262μs | 115.002μs | 155.326μs | ±10.46% |
| RedisLimiterStoreBench | benchRelease | redis (phpredis) | 500 | 10 | 1.752mb | 110.514μs | 89.918μs | 132.700μs | ±12.42% |
| RedisLimiterStoreBench | benchRelease | valkey (phpredis) | 500 | 10 | 1.752mb | 104.240μs | 94.680μs | 137.534μs | ±11.06% |
| RedisLimiterStoreBench | benchRelease | redis (predis) | 500 | 10 | 1.752mb | 118.977μs | 95.040μs | 122.714μs | ±10.17% |
| RedisLimiterStoreBench | benchRelease | valkey (predis) | 500 | 10 | 1.752mb | 113.889μs | 107.956μs | 145.854μs | ±10.05% |
| RedisLimiterStoreBench | benchRoundTrip | redis (phpredis) | 500 | 10 | 1.752mb | 87.602μs | 83.532μs | 104.124μs | ±7.03% |
| RedisLimiterStoreBench | benchRoundTrip | valkey (phpredis) | 500 | 10 | 1.752mb | 98.323μs | 79.050μs | 131.158μs | ±15.67% |
| RedisLimiterStoreBench | benchRoundTrip | redis (predis) | 500 | 10 | 1.752mb | 88.202μs | 82.388μs | 96.536μs | ±4.36% |
| RedisLimiterStoreBench | benchRoundTrip | valkey (predis) | 500 | 10 | 1.752mb | 104.501μs | 83.618μs | 111.408μs | ±10.78% |
| CacheLimiterStoreBench | benchAllowed | array | 500 | 10 | 2.857mb | 6.095μs | 5.452μs | 7.598μs | ±9.64% |
| CacheLimiterStoreBench | benchAllowed | redis (phpredis) | 500 | 10 | 1.752mb | 288.529μs | 265.322μs | 316.218μs | ±5.27% |
| CacheLimiterStoreBench | benchAllowed | memcached | 500 | 10 | 2.856mb | 299.887μs | 256.200μs | 443.798μs | ±16.57% |
| CacheLimiterStoreBench | benchRejected | array | 500 | 10 | 2.857mb | 9.000μs | 8.776μs | 13.010μs | ±12.92% |
| CacheLimiterStoreBench | benchRejected | redis (phpredis) | 500 | 10 | 1.752mb | 410.274μs | 371.088μs | 470.710μs | ±6.56% |
| CacheLimiterStoreBench | benchRejected | memcached | 500 | 10 | 2.856mb | 387.730μs | 376.406μs | 414.726μs | ±3.02% |
| CacheLimiterStoreBench | benchRelease | array | 500 | 10 | 2.857mb | 4.513μs | 4.208μs | 6.852μs | ±15.45% |
| CacheLimiterStoreBench | benchRelease | redis (phpredis) | 500 | 10 | 1.752mb | 207.251μs | 188.642μs | 264.112μs | ±9.95% |
| CacheLimiterStoreBench | benchRelease | memcached | 500 | 10 | 2.856mb | 192.683μs | 169.712μs | 196.634μs | ±5.36% |
| CacheLimiterStoreBench | benchRoundTrip | array | 500 | 10 | 2.857mb | 0.305μs | 0.270μs | 0.462μs | ±16.99% |
| CacheLimiterStoreBench | benchRoundTrip | redis (phpredis) | 500 | 10 | 1.752mb | 91.580μs | 81.590μs | 120.558μs | ±12.49% |
| CacheLimiterStoreBench | benchRoundTrip | memcached | 500 | 10 | 2.856mb | 82.013μs | 72.710μs | 101.006μs | ±10.57% |
