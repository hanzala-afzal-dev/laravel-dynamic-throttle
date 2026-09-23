<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exceptions;

use RuntimeException;
use Throwable;

/**
 * The counter store could not be used. The failure policy decides what
 * happens to the request (ADR-0010).
 */
final class StorageUnavailableException extends RuntimeException
{
    /**
     * Never passes on the previous exception's code: database exceptions use string
     * SQLSTATE codes such as "HY000", which the integer code parameter rejects with
     * a TypeError under strict types.
     */
    public static function because(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
