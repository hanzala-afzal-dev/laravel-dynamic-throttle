<?php

declare(strict_types=1);

/*
 * ADR-0019: the lowest Laravel version CI installs must equal the floor that
 * composer.json declares for that major, so every version users can install
 * is a version CI has tested.
 *
 *   composer update --prefer-lowest --with "laravel/framework:^13.0"
 *   php scripts/check-laravel-floor.php 13
 */

use Composer\InstalledVersions;

require __DIR__.'/../vendor/autoload.php';

$major = $argv[1] ?? '';

if (! ctype_digit($major)) {
    fwrite(STDERR, "usage: php scripts/check-laravel-floor.php <laravel-major>\n");
    exit(2);
}

$composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true, flags: JSON_THROW_ON_ERROR);
$constraint = is_array($composer) ? (string) ($composer['require']['laravel/framework'] ?? '') : '';

if (preg_match('/\^('.$major.'\.\d+\.\d+)/', $constraint, $match) !== 1) {
    fwrite(STDERR, "composer.json declares no ^{$major}.x.y floor for laravel/framework (found \"{$constraint}\").\n");
    exit(1);
}

$declared = $match[1];
$installed = ltrim((string) InstalledVersions::getPrettyVersion('laravel/framework'), 'v');

if ($installed !== $declared) {
    fwrite(STDERR, sprintf(
        "prefer-lowest installed laravel/framework %s, but composer.json declares ^%s, so the versions in between are never tested.\n"
        ."Raise the floor in composer.json and README.md to ^%s (ADR-0019).\n",
        $installed,
        $declared,
        $installed,
    ));
    exit(1);
}

echo "laravel/framework {$installed} is the declared floor for Laravel {$major}.\n";
