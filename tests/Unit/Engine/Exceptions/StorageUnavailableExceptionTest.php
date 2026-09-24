<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;

covers(StorageUnavailableException::class);

it('SPEC-002 AC-11: keeps the message and the store\'s exception, but never its code', function (): void {
    // Database exceptions carry string SQLSTATE codes, which the integer code cannot hold.
    $previous = new class('SQLSTATE[HY000]: General error: 1 no such table: cache') extends RuntimeException
    {
        protected $code = 'HY000';
    };

    $exception = StorageUnavailableException::because('The limiter store could not count the request.', $previous);

    expect($exception->getMessage())->toBe('The limiter store could not count the request.')
        ->and($exception->getPrevious())->toBe($previous)
        ->and($exception->getCode())->toBe(0);
});

it('SPEC-002 AC-11: works without a previous exception', function (): void {
    $exception = StorageUnavailableException::because('Memcached could not read the previous window.');

    expect($exception->getPrevious())->toBeNull()
        ->and($exception->getCode())->toBe(0);
});
