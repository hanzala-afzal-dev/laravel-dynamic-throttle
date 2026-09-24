<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/benchmarks',
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // Reads the PHP constraint from composer.json (^8.3), so Rector never
    // suggests syntax that the lowest supported PHP version cannot run.
    ->withPhpSets()
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        earlyReturn: true,
    )
    ->withImportNames(importShortClasses: false, removeUnusedImports: true);
