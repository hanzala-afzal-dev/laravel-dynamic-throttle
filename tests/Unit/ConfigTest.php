<?php

declare(strict_types=1);

/*
| The DYNAMIC_THROTTLE_ENABLED kill switch (ADR-0011) is used during incidents,
| so every usual spelling of "off" must disable the package.
*/

function loadPackageConfig(): array
{
    return require __DIR__.'/../../config/dynamic-throttle.php';
}

dataset('kill switch values', [
    'false' => ['false', false],
    '0' => ['0', false],
    'off' => ['off', false],
    'no' => ['no', false],
    'true' => ['true', true],
    '1' => ['1', true],
    'on' => ['on', true],
    'yes' => ['yes', true],
]);

it('reads the kill switch from DYNAMIC_THROTTLE_ENABLED', function (string $value, bool $enabled): void {
    $_SERVER['DYNAMIC_THROTTLE_ENABLED'] = $value;

    try {
        expect(loadPackageConfig()['enabled'])->toBe($enabled);
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_ENABLED']);
    }
})->with('kill switch values');

it('is enabled when DYNAMIC_THROTTLE_ENABLED is not set', function (): void {
    expect(loadPackageConfig()['enabled'])->toBeTrue();
});

/*
| SPEC-005 AC-7: the database connection for the package's tables.
*/

it('AC-7: reads the database connection from DYNAMIC_THROTTLE_DB_CONNECTION', function (): void {
    $_SERVER['DYNAMIC_THROTTLE_DB_CONNECTION'] = 'secondary';

    try {
        expect(loadPackageConfig()['database']['connection'])->toBe('secondary');
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_DB_CONNECTION']);
    }
});

it('AC-7: uses the default connection when DYNAMIC_THROTTLE_DB_CONNECTION is not set', function (): void {
    expect(loadPackageConfig())->toHaveKey('database')
        ->and(loadPackageConfig()['database']['connection'])->toBeNull();
});

/*
| SPEC-006 AC-21: the manifest's read path and scheduled compile.
*/

it('AC-21: refreshes the in-memory manifest every 2 seconds unless DYNAMIC_THROTTLE_MANIFEST_REFRESH says otherwise', function (): void {
    expect(loadPackageConfig()['manifest'])->toBe(['refresh_seconds' => 2, 'schedule' => true]);

    $_SERVER['DYNAMIC_THROTTLE_MANIFEST_REFRESH'] = '0';

    try {
        expect(loadPackageConfig()['manifest']['refresh_seconds'])->toBe(0);
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_MANIFEST_REFRESH']);
    }
});

it('AC-21: falls back to 2 seconds for a refresh that is not a whole number of seconds', function (string $value): void {
    $_SERVER['DYNAMIC_THROTTLE_MANIFEST_REFRESH'] = $value;

    try {
        expect(loadPackageConfig()['manifest']['refresh_seconds'])->toBe(2);
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_MANIFEST_REFRESH']);
    }
})->with(['-1', 'soon', '1.5']);

/*
| SPEC-007 AC-7 and AC-17: how many matched rules apply to one request.
*/

it('AC-17: applies at most 5 matched rules per request unless DYNAMIC_THROTTLE_MAX_RULES says otherwise', function (): void {
    expect(loadPackageConfig()['matching'])->toBe(['max_rules_per_request' => 5]);

    $_SERVER['DYNAMIC_THROTTLE_MAX_RULES'] = '8';

    try {
        expect(loadPackageConfig()['matching']['max_rules_per_request'])->toBe(8);
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_MAX_RULES']);
    }
});

it('AC-7: falls back to 5 rules for a cap below 1 or not a whole number', function (string $value): void {
    $_SERVER['DYNAMIC_THROTTLE_MAX_RULES'] = $value;

    try {
        expect(loadPackageConfig()['matching']['max_rules_per_request'])->toBe(5);
    } finally {
        unset($_SERVER['DYNAMIC_THROTTLE_MAX_RULES']);
    }
})->with(['0', '-1', 'many', '1.5']);
