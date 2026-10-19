# Performance

What the middleware adds to a request, against no limiter and against Laravel's own `throttle`, each counting on the same Redis. Measured through the whole HTTP kernel, with 200 requests per case, twice ([the full results](../../benchmarks/results/phase-6-comparison.md)).

| Case | p50 | p95 | Added at p50 | Redis calls | Database queries |
|---|---:|---:|---:|---:|---:|
| No limiter | 0.18–0.19 ms | 0.30–0.32 ms | | 0 | 0 |
| Laravel's `throttle` | 1.16–1.22 ms | 1.9–2.2 ms | 1.0 ms | 5 | 0 |
| Laravel's `throttle`, with `throttleWithRedis()` | 0.74–0.80 ms | 1.2–1.4 ms | 0.55–0.6 ms | 2 | 0 |
| Dynamic Throttle, 1 rule, Octane | 0.92–0.95 ms | 1.3–1.5 ms | 0.75 ms | 1 | 0 |
| Dynamic Throttle, 1 rule, PHP-FPM with APCu | 0.98–0.99 ms | 1.5 ms | 0.8 ms | 1 | 0 |
| Dynamic Throttle, 1 rule, PHP-FPM without APCu | 1.09–1.24 ms | 1.9 ms | 0.9–1.06 ms | 2 | 0 |
| Dynamic Throttle, 100 rules, Octane | 1.03–1.04 ms | 1.6 ms | 0.85 ms | 1 | 0 |
| Dynamic Throttle, 100 rules, PHP-FPM with APCu | 1.13–1.17 ms | 1.6–1.7 ms | 0.95–0.98 ms | 1 | 0 |
| Dynamic Throttle, 100 rules, PHP-FPM without APCu | 1.77–1.82 ms | 2.5–2.8 ms | 1.6 ms | 2 | 0 |

The times run until the response is sent. With 100 rules, 3 match the request.

- **Against Laravel's `throttle`,** a request costs a little less with Dynamic Throttle on Octane or PHP-FPM with APCu. `throttleWithRedis()` is cheaper still: Dynamic Throttle's one Redis call runs a longer script, and it matches the rules and hashes the client's identity first.
- **Enable APCu on PHP-FPM.** Without it, each request also reads the rules from Redis: with 100 rules, that nearly doubles what the package adds ([storage and runtimes](storage-and-runtimes.md)).
- **After the response,** Dynamic Throttle writes its [telemetry](telemetry.md) in one more Redis call. The client doesn't wait for it on PHP-FPM, but the worker stays busy 0.5 to 0.6 ms longer. `DYNAMIC_THROTTLE_TELEMETRY=false` turns it off, and `DYNAMIC_THROTTLE_SAMPLE_RATE` records a share of requests.
- **No database queries,** in any case: the rules are compiled into your cache ([ADR-0007](../adr/0007-rule-storage-and-distribution.md)).
- **Your Redis is likely further away.** Here it is local, about 0.15 ms per round trip. Across a network, each call costs more, so the number of calls counts: 5 for Laravel's `throttle`, 2 for `throttleWithRedis()`, and 1 for Dynamic Throttle, 2 on PHP-FPM without APCu.

**The machine:** a cloud VM with 4 vCPUs of an Intel Xeon at 2.10 GHz, shared with other tenants. PHP 8.4 with OPcache and APCu, phpredis 6.3, Laravel 13.33, and Redis 7.4 in Docker on the same machine. Your numbers will differ; the comparison between cases is what carries over.

## Run it yourself

```bash
docker compose up -d redis
vendor/bin/phpbench run benchmarks/Http/ComparisonBench.php --report=engine --dump-file=run.xml
php benchmarks/percentiles.php run.xml
php -d apc.enable_cli=1 benchmarks/round-trips.php
```

The first command takes about 20 minutes: each request runs in a process of its own, so the 200 per case give the spread of single requests ([ADR-0016](../adr/0016-testing-strategy.md)).
