# Benchmark: the middleware at the Phase 2 exit

The Phase 2 exit review, on 2026-09-28, re-ran SPEC-010's middleware benchmark (`phase-2-middleware.md`) on `main`, after SPEC-011 (dry-run events) and SPEC-012 (the failure policy). ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-28
- Code: `main` at `55580bd`, the merge of SPEC-012.
- Command: `vendor/bin/phpbench run benchmarks/Http/MiddlewareBench.php --report=engine --dump-file=…`, then `php benchmarks/percentiles.php <dump>`. The setup is SPEC-010's:
  - One request is `handle()` on the middleware, with a `$next` that returns at once.
  - It matches 3 of 100 rules, and each counts it by IP and allows it.
  - Each iteration is one request in a process of its own, after 20 warm-up requests: 200 iterations per case.
- Raw data: PHPBench's XML dump was removed from the repository on 2026-10-18 (ADR-0027). Git history keeps it: `git log --diff-filter=D -- 'benchmarks/results/*.xml'` finds the commit that removed it.

## Machine
- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants. SPEC-010's run had a Xeon at 2.80 GHz.
- Linux 6.18.44, Ubuntu 24.04.4.
- PHP 8.4.19 (NTS) with OPcache and no JIT, Xdebug not loaded, PCOV loaded but disabled. APCu is loaded and off in the CLI, so it plays no part.
- Laravel 13.33.0, PHPBench 1.7.0.
- Redis 7.4.11 in Docker, reached over the Docker bridge network. A `PING` took 123 µs at p50 and 182 µs at p95, over 2,000 of them.

## Results
Microseconds per request, 200 requests per case:

| Store | Manifest | p50 | p95 | p99 | p95 at SPEC-010 (run 2) |
|---|---|---:|---:|---:|---:|
| array | in memory (Octane) | 199 | 337 | 420 | 336 |
| local Redis | in memory (Octane) | 864 | 1,253 | 1,389 | 1,196 |
| array | read per request (PHP-FPM) | 562 | 867 | 1,116 | 831 |
| local Redis | read per request (PHP-FPM) | 1,522 | 2,296 | 2,713 | 2,311 |

- **SPEC-011 and SPEC-012 cost nothing measurable.** Every case is within the noise of SPEC-010's runs. The failure policy adds a cooldown check to each request, and it reads the clock only once a cooldown was started.
- **The 1 ms target is missed on local Redis**, as at SPEC-010: 1.25 ms with the manifest in memory, and 2.3 ms with the manifest read per request. It is met on the array store, where no network is involved.
- Where the time goes is unchanged since SPEC-010's breakdown:
  - three Redis round trips, one per matched rule, one after another;
  - on PHP-FPM, reading the manifest from the cache and decoding it, on every request;
  - Laravel's container building the package's services by reflection.

## Decisions (the Phase 2 exit review)
The maintainer accepted all four recommendations on 2026-09-28:
1. **One store round trip per request**, in one Lua script on the Redis driver: [ADR-0026](../../docs/adr/0026-one-round-trip-per-request.md), built in SPEC-027 (Phase 3).
2. **The manifest's APCu cache on PHP-FPM**, ADR-0007's L1, built in SPEC-028 (Phase 3).
3. **Explicit container bindings** for the request path's services, in a measured `perf:` PR (Phase 3).
4. **The target is restated.** The absolute number mostly follows the network, so it becomes: one store round trip per request, and under 0.5 ms of the package's own time at p95, measured on the array store. It is a Phase 3 exit criterion now (`docs/roadmap.md`).
