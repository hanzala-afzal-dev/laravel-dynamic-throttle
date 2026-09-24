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
