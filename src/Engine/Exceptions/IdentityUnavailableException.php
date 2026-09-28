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
    private function __construct(
        string $message,
        ?Throwable $previous,
        /**
         * The client caused it: its IP cannot be read, since it sent forwarded headers
         * that disagree. Code cannot prevent that, so strict mode never rethrows it (SPEC-012).
         */
        public readonly bool $fromClient = false,
    ) {
        // Never the previous exception's code, which may be a string (see StorageUnavailableException).
        parent::__construct($message, 0, $previous);
    }

    public static function because(string $message, ?Throwable $previous = null): self
    {
        return new self($message, $previous);
    }

    /**
     * The client IP cannot be read, such as when trusted forwarded headers disagree.
     */
    public static function unreadableClientIp(string $message, Throwable $previous): self
    {
        return new self($message, $previous, fromClient: true);
    }
}
