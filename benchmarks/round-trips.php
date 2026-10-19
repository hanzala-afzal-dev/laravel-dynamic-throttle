<?php

declare(strict_types=1);

/*
| Issue #101: the Redis calls and database queries of one request, for each
| case of ComparisonBench, on local Redis, in a warm process (ADR-0016). Run
| with APCu enabled in the CLI, as phpbench.json runs the benchmarks:
|
|     php -d apc.enable_cli=1 benchmarks/round-trips.php
*/

use Afzal\DynamicThrottle\Benchmarks\Http\ComparisonBench;

require __DIR__.'/../vendor/autoload.php';

$bench = new ComparisonBench;

printf("%-62s %15s %15s %8s\n", 'case', 'Redis, before', 'Redis, after', 'queries');

foreach ($bench->cases() as $name => $params) {
    $bench->setUp($params);
    $counts = $bench->count();
    $bench->tearDown();

    printf("%-62s %15d %15d %8d\n", $name, $counts['redis'], $counts['redis_after'], $counts['queries']);
}
