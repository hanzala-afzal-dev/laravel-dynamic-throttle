<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A rule's identity could not be resolved: a custom scope that is not
 * registered, a resolver that failed or returned something unusable, or a
 * guard that failed (SPEC-008). The rule's failure policy decides what
 * happens to the request (ADR-0020 point 1).
 */
final class IdentityUnavailableException extends RuntimeException
{
    /**
     * Never passes on the previous exception's code, which may be a string (see
     * StorageUnavailableException).
     */
    public static function because(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
