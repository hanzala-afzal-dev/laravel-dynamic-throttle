<?php

declare(strict_types=1);

/*
 * Fails when a runtime dependency (what users install) has no permissive license.
 *
 *   composer licenses --no-dev --format=json | php scripts/check-licenses.php
 *
 * A package that lists several licenses is available under any one of them,
 * so it passes when at least one is on the allowlist.
 */

const ALLOWED = [
    '0BSD',
    'Apache-2.0',
    'BSD-2-Clause',
    'BSD-3-Clause',
    'ISC',
    'MIT',
    'MIT-0',
    'Unlicense',
];

$report = json_decode((string) stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);

if (! is_array($report) || ! isset($report['dependencies']) || ! is_array($report['dependencies'])) {
    fwrite(STDERR, "Expected the output of `composer licenses --format=json` on stdin.\n");
    exit(2);
}

$offenders = [];

foreach ($report['dependencies'] as $package => $info) {
    $licenses = is_array($info) && is_array($info['license'] ?? null) ? $info['license'] : [];

    if (array_intersect($licenses, ALLOWED) === []) {
        $offenders[] = sprintf('%s (%s)', $package, $licenses === [] ? 'no license' : implode(' OR ', $licenses));
    }
}

if ($offenders !== []) {
    fwrite(STDERR, "Runtime dependencies without an allowed license:\n  - ".implode("\n  - ", $offenders)."\n");
    exit(1);
}

printf("%d runtime dependencies checked, all permissively licensed.\n", count($report['dependencies']));
