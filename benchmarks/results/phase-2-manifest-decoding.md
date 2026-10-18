# Benchmark: manifest decoding (Phase 2)

On PHP-FPM without ADR-0007's L1 cache (APCu), every request decodes the manifest. So the SPEC-007 review asked for a `perf:` PR that makes decoding cheaper without changing what it accepts (roadmap, Phase 2). ADR-0016 publishes numbers only with the hardware stated.

- Date: 2026-09-25
- Code: before is `5e57b63`, after is the `perf:` commit that adds this file (branch `phase-2/rules-and-middleware`). The after runs ran on that change before it was committed, so both XML files record `5e57b63` as their version. A reviewer's later runs of the committed code gave the same numbers.
- Command: `vendor/bin/phpbench run benchmarks/Engine/RuleMatcherBench.php --filter=benchDecode --report=engine --retry-threshold=5`. `benchDecode` is a whole load: `json_decode`, every rule's checks, then the index (ADR-0023). Each iteration is 100 loads, 10 iterations after one warm-up.
- Raw data: PHPBench's XML dump was removed from the repository on 2026-10-18 (ADR-0027). Git history keeps it: `git log --diff-filter=D -- 'benchmarks/results/*.xml'` finds the commit that removed it.

## Machine
- A Claude Code cloud container: a KVM guest with 4 vCPUs of an Intel Xeon at 2.80 GHz and 15 GiB of RAM. The hardware is shared with other tenants.
- Linux 6.18.44, Ubuntu 24.04.4.
- PHP 8.4.19 (NTS) with OPcache and no JIT, Xdebug not loaded, PCOV loaded but disabled.
- Laravel 13.33.0, PHPBench 1.7.0.

## What changed
`CompiledRule::fromArray()` accepts and rejects exactly what it did, with the same messages. `tests/Unit/Manifest/CompiledRuleTest.php` pins both, and was committed first.
- It compares a rule's keys with `FIELDS`, in the order the manifest writes them, and sorts them only when they come in another order. Sorting every rule's keys, and a copy of `FIELDS`, took 1.4 µs of the 3.2 µs a rule cost.
- It checks each field inline, and calls a helper only to build an error.

## Results
Mode per load, from runs of the old and new code in turn:

| Run | 100 rules | 500 rules |
|---|---:|---:|
| Before, run 1 | 566 µs | 2,516 µs |
| After, run 1 | 344 µs | 1,539 µs |
| Before, run 2 | 497 µs | 2,472 µs |
| After, run 2 | 302 µs | 1,583 µs |

A load costs 36% to 40% less, depending on the run and the size. That is well past the noise: in the Phase 1 baseline, runs of the same code differed by 7.8% at the median and 17% at worst, so it treats differences under 20% as noise (`phase-1-baseline.md`, measured on a 2.10 GHz Xeon of the same kind of container). The raw data is from run 2.

Where a load of 100 rules goes, timed part by part with `hrtime()` by a throwaway script (best of 10 rounds of 200 loads, run as `php -d opcache.enable_cli=1 -d pcov.enabled=0`, as PHPBench runs; with PCOV on, every number is about 50% higher):

| Part | Before | After |
|---|---:|---:|
| `json_decode` | 123 µs | 124 µs |
| Every rule's checks | 324 µs | 144 µs |
| Building the index | 24 µs | 24 µs |
| The whole load | 485 µs | 301 µs |

Matching a request stays at 4.4 µs with 100 or 500 rules.

## What is left
- At 100 rules, a load still costs about 0.3 ms of the roadmap's 1 ms target for the whole middleware. Every rule's checks (144 µs) are still the largest part, with `json_decode` (124 µs) close behind.
- Most of the rest could go with a more compact manifest format, which is a new `FORMAT`, or with ADR-0007's L1 cache on PHP-FPM. The roadmap decides the L1 cache with the Phase 2 exit benchmark.
