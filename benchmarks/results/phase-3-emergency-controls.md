# Benchmark: the emergency controls on the request path (SPEC-013)

SPEC-013 adds two runtime settings to the manifest, bypass and the scale factor. Every request now reads the clock once more, checks bypass, and scales each matched rule's limit. This records what that costs. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-10-01
- Code, run alternately, twice, each in the same clone of the repository:
  - Before: `189153b`, SPEC-028's head, with no settings.
  - After: `5aabf20`, SPEC-013 with its review's fixes. No setting is on, so every request pays the checks and nothing else changes.
- Machine: a Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.10 GHz and 15 GiB of RAM, shared with other tenants, as in `phase-3-apcu-manifest.md`. PHP 8.4.19 (NTS) with OPcache, APCu 5.1.28, Laravel 13.33.0, PHPBench 1.7.0, and Redis 7.4.11 in Docker.

`MiddlewareBench`, with 100 rules of which 3 match. Microseconds per request, 200 requests per case.

| Store | Manifest | Round | p50, before | p50, after | p95, before | p95, after |
|---|---|---:|---:|---:|---:|---:|
| array | PHP-FPM with APCu | 1 | 226 | 232 | 387 | 437 |
| | | 2 | 239 | 248 | 401 | 429 |
| array | in memory (Octane) | 1 | 159 | 161 | 275 | 344 |
| | | 2 | 161 | 162 | 252 | 288 |
| array | read per request (PHP-FPM without APCu) | 1 | 477 | 493 | 926 | 844 |
| | | 2 | 487 | 508 | 951 | 989 |
| local Redis | PHP-FPM with APCu | 1 | 775 | 857 | 1,336 | 1,471 |
| | | 2 | 753 | 772 | 1,153 | 1,173 |
| local Redis | in memory (Octane) | 1 | 501 | 600 | 1,085 | 1,072 |
| | | 2 | 556 | 611 | 1,024 | 1,142 |
| local Redis | read per request (PHP-FPM without APCu) | 1 | 1,541 | 1,611 | 2,377 | 2,506 |
| | | 2 | 1,664 | 1,550 | 2,477 | 2,457 |

- **On the array store,** a request costs 1 to 21 µs more at p50. The added work alone costs about 4 µs (below), so the rest is this machine's noise.
- **At p95,** the differences go both ways, from −82 to +69 µs on the array store. The added work's own p99 is 12 µs, so they are this shared host's tails, as `phase-3-apcu-manifest.md` saw between runs of the same code.
- **On local Redis,** both directions again, by up to 135 µs: the round trip to Redis in Docker dominates.
- **Raw data:** round 2's runs are in `phase-3-emergency-controls-before.xml` and `phase-3-emergency-controls-after.xml`, next to this file. Each run swapped in its code's `src` only, so both dumps name the clone's commit, `5aabf20`. Round 1's numbers are from its console output.

### The added work alone
20,000 samples in one process, of what SPEC-013 adds to a request with no setting on: reading the clock, `bypasses()`, `scalePercent()`, and `Scale::limit()` for three rules. PHP 8.4.19 without OPcache.

| p50 | p95 | p99 |
|---:|---:|---:|
| 4.1 µs | 5.5 µs | 11.6 µs |

Nearly all of it is the clock: `LaravelClock` builds a Carbon instance to follow Laravel's `travel()` (ADR-0006).

### Against the target
The Phase 3 exit criteria (`docs/roadmap.md`): the package's own time, on the array store, under 0.5 ms at p95 on Octane and on PHP-FPM with APCu.
- PHP-FPM with APCu: 429 to 437 µs at p95 after, 387 to 401 before. **Still met.**
- In memory, as on Octane: 288 to 344 µs at p95 after. **Still met.**
