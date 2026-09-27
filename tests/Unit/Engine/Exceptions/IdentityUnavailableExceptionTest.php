<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;

covers(IdentityUnavailableException::class);

it('SPEC-008 AC-12: keeps the message and the cause, but never the cause\'s code', function (): void {
    // A guard's token lookup can fail with a database exception, whose SQLSTATE code the integer code cannot hold.
    $cause = new class('SQLSTATE[HY000]: General error: 1 no such table: personal_access_tokens') extends RuntimeException
    {
        protected $code = 'HY000';
    };

    $exception = IdentityUnavailableException::because('The identity for user could not be resolved.', $cause);

    expect($exception->getMessage())->toBe('The identity for user could not be resolved.')
        ->and($exception->getPrevious())->toBe($cause)
        ->and($exception->getCode())->toBe(0);
});

it('SPEC-008 AC-12: works without a cause', function (): void {
    $exception = IdentityUnavailableException::because('The identity for custom:plan could not be resolved.');

    expect($exception->getPrevious())->toBeNull()
        ->and($exception->getCode())->toBe(0);
});

it('SPEC-012 AC-9: marks a client IP that cannot be read as the client\'s doing, which strict mode never rethrows', function (): void {
    $cause = new RuntimeException('Conflicting forwarded headers.');

    $client = IdentityUnavailableException::unreadableClientIp('The client IP could not be read.', $cause);
    $app = IdentityUnavailableException::because('The identity for custom:plan could not be resolved.', $cause);

    expect([$client->fromClient, $app->fromClient])->toBe([true, false])
        ->and([$client->getMessage(), $client->getPrevious(), $client->getCode()])->toBe(['The client IP could not be read.', $cause, 0]);
});
