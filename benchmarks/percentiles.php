<?php

declare(strict_types=1);

/*
| Per-iteration percentiles from a PHPBench XML dump, for benchmarks whose
| iterations are single requests, such as MiddlewareBench (ADR-0016):
|
|     vendor/bin/phpbench run benchmarks/Http/MiddlewareBench.php --dump-file=run.xml
|     php benchmarks/percentiles.php run.xml
*/

$file = $argv[1] ?? null;

if (! is_string($file) || ! is_file($file)) {
    fwrite(STDERR, "Usage: php benchmarks/percentiles.php <phpbench-dump.xml>\n");

    exit(1);
}

$xml = simplexml_load_file($file);

if ($xml === false) {
    fwrite(STDERR, "{$file} is not XML.\n");

    exit(1);
}

/**
 * The value below which the given share of the sorted samples lies (nearest rank).
 *
 * @param  non-empty-list<float>  $sorted
 */
function percentile(array $sorted, float $share): float
{
    return $sorted[max(0, (int) ceil($share * count($sorted)) - 1)];
}

printf("%-18s %-45s %5s %8s %8s %8s %8s\n", 'subject', 'set', 'n', 'p50 µs', 'p95 µs', 'p99 µs', 'max µs');

foreach ($xml->xpath('//subject') ?: [] as $subject) {
    foreach ($subject->xpath('variant') ?: [] as $variant) {
        $times = [];

        foreach ($variant->xpath('iteration') ?: [] as $iteration) {
            $times[] = (float) $iteration['time-avg'];
        }

        if ($times === []) {
            continue;
        }

        sort($times);
        printf(
            "%-18s %-45s %5d %8.1f %8.1f %8.1f %8.1f\n",
            (string) $subject['name'],
            (string) ($variant->{'parameter-set'}['name'] ?? ''),
            count($times),
            percentile($times, 0.50),
            percentile($times, 0.95),
            percentile($times, 0.99),
            $times[count($times) - 1],
        );
    }
}
