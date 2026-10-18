# Benchmark: the middleware's steps after SPEC-014

SPEC-014 moves the middleware's early exits into `Untouched`, which `dynamic-throttle:explain` shares, and splits `RuleEvaluator::evaluate()` into `plan()`, the count and `decide()`. No request does more work: an untouched request builds one small object at its exit, and a counted one builds one `Plan`. This records that the request path's cost did not move. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-04
- Code, run alternately, twice, each in the same clone of the repository:
  - Before: `a7799ec`, SPEC-013's head, which main now holds.
  - After: `c4681f9`, SPEC-014 with its review's fixes.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants, as in `phase-3-emergency-controls.md`. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

`MiddlewareBench`, with 100 rules of which 3 match. Microseconds per request, 200 requests per case.

| Store | Manifest | Round | p50, before | p50, after | p95, before | p95, after |
|---|---|---:|---:|---:|---:|---:|
| array | PHP-FPM with APCu | 1 | 222 | 231 | 401 | 364 |
| | | 2 | 216 | 223 | 380 | 409 |
| array | in memory (Octane) | 1 | 165 | 159 | 282 | 285 |
| | | 2 | 163 | 162 | 278 | 277 |
| array | read per request (PHP-FPM without APCu) | 1 | 471 | 477 | 869 | 881 |
| | | 2 | 476 | 468 | 857 | 833 |
| local Redis | PHP-FPM with APCu | 1 | 650 | 742 | 1,089 | 1,236 |
| | | 2 | 781 | 694 | 1,247 | 1,137 |
| local Redis | in memory (Octane) | 1 | 514 | 535 | 984 | 970 |
| | | 2 | 537 | 591 | 1,043 | 1,018 |
| local Redis | read per request (PHP-FPM without APCu) | 1 | 1,553 | 1,503 | 2,295 | 2,386 |
| | | 2 | 1,507 | 1,548 | 2,515 | 2,308 |

- **On the array store,** the differences at p50 go both ways, from −8 to +9 µs, and at p95 from −37 to +29 µs. That is this shared host's noise, as `phase-3-emergency-controls.md` saw between runs: the cost did not move.
- **On local Redis,** both directions again, by up to 92 µs at p50: the round trip to Redis in Docker dominates.
- **Raw data:** PHPBench's XML dump was removed from the repository on 2026-10-18 (ADR-0027). Git history keeps it: `git log --diff-filter=D -- 'benchmarks/results/*.xml'` finds the commit that removed it.

### Against the target
The Phase 3 exit criteria (`docs/roadmap.md`): the package's own time, on the array store, under 0.5 ms at p95 on Octane and on PHP-FPM with APCu.
- PHP-FPM with APCu: 364 to 409 µs at p95 after, 380 to 401 before. **Still met.**
- In memory, as on Octane: 277 to 285 µs at p95 after. **Still met.**
