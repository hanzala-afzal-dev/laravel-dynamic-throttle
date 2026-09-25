# Benchmark: exemptions (Phase 2)

SPEC-009 AC-23 asks what exemptions add to every manifest load, and what checking a request against them costs. ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-25
- Code: the SPEC-009 implementation on branch `phase-2/rules-and-middleware`, run before it was committed on top of `3762a98`.
- Command: `vendor/bin/phpbench run benchmarks/Engine/ExemptionSetBench.php --report=engine --retry-threshold=5`.
  - `benchDecode` is a whole manifest load: `json_decode`, every rule's and every exemption's checks, the rule index and the exemption set. Each iteration is 100 loads, 10 iterations after one warm-up.
  - `benchCheck` is one rule's check for a request that no exemption frees, so every subject is asked and every prefix length looked up: the most a check costs. The request's user and custom values arrive hashed, as the resolver gives them. Each iteration is 1,000 checks.
  - The manifests hold 100 rules, and none, 100 or 1,000 exemptions. Half are IPv4 ranges of four prefix lengths (`/32` and `/16` global, `/24` and `/8` of the checked rule), a third are the checked rule's users, and the rest are global custom values.
- Raw data: `phase-2-exemptions.xml`, next to this file, holds run 2. Run 1's numbers are from its console output. `vendor/bin/phpbench report --file=benchmarks/results/phase-2-exemptions.xml --report=engine` prints the table.

## Machine
- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.80 GHz and 15 GiB of RAM. The hardware is shared with other tenants.
- Linux 6.18.44, Ubuntu 24.04.4.
- PHP 8.4.19 (NTS) with OPcache and no JIT, Xdebug not loaded, PCOV loaded but disabled.
- Laravel 13.33.0, PHPBench 1.7.0.

## Results
Mode per operation, from two runs:

| Exemptions | Load, run 1 | Load, run 2 | Check, run 1 | Check, run 2 |
|---|---:|---:|---:|---:|
| None | 304 µs | 304 µs | 0.10 µs | 0.10 µs |
| 100 | 546 µs | 555 µs | 2.38 µs | 2.48 µs |
| 1,000 | 2,867 µs | 2,789 µs | 2.48 µs | 2.53 µs |

- **A check does not grow with the number of exemptions.** 100 and 1,000 differ by less than the noise: the Phase 1 baseline treats differences under 20% as noise (`phase-1-baseline.md`). A rule with no exemptions, own or global, costs 0.1 µs.
- On the request path, the resolver adds one clock read per request, about 1.4 µs with Laravel's clock (`phase-1-baseline.md`), and none when the manifest has no exemptions.
- **A load grows with them**, by about 2.5 µs per exemption: 100 exemptions add about a quarter of a millisecond, and 1,000 add about 2.5 ms.

Where a load of 100 rules and 1,000 exemptions goes, timed part by part with `hrtime()` by a throwaway script, which is not kept (best of 10 rounds of 20 loads, run as `php -d opcache.enable_cli=1 -d pcov.enabled=0`, as PHPBench runs):

| Part | Time |
|---|---:|
| `json_decode` | 859 µs |
| The checks of 500 ip exemptions, of which parsing and normalizing the ranges is 523 µs | 975 µs |
| The checks of 500 user and custom exemptions | 455 µs |
| Grouping the exemption set | 129 µs |
| The whole load | 2,795 µs |

The rest of the load is the rules' checks and their index (`phase-2-manifest-decoding.md`).

## What this means
- On PHP-FPM without ADR-0007's L1 cache (APCu), every request pays the load. With 100 exemptions, a load of 100 rules goes from 0.3 ms to 0.55 ms of the roadmap's 1 ms target for the whole middleware. With 1,000, a load alone is almost 3 ms.
- Octane, queue workers and an L1 cache load the manifest once, so they pay only the check.
- SPEC-009 decision 4 set no cap. The Phase 2 exit benchmark, which decides the L1 cache, also decides whether SPEC-016's health check warns above some number of exemptions.
- The cheapest savings, if needed later, are in the ip exemptions' checks: each range is parsed, then printed again to check that the manifest holds it normalized (523 µs of their 975 µs).
