<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

use InvalidArgumentException;

/**
 * Identifies one counter: a rule and the hash of a client identity (ADR-0009).
 *
 * Only a hash is accepted, so a raw IP address, token or email address can
 * never end up in a cache key.
 */
final readonly class CounterKey
{
    public function __construct(
        public string $ruleId,
        public string $identityHash,
    ) {
        // \A and \z, because $ would also match before a trailing newline.
        if (preg_match('/\A[A-Za-z0-9_-]{1,64}\z/', $ruleId) !== 1) {
            throw new InvalidArgumentException('A rule id must be 1 to 64 characters from A-Z, a-z, 0-9, "_" and "-".');
        }

        if (preg_match('/\A[0-9a-f]{64}\z/', $identityHash) !== 1) {
            throw new InvalidArgumentException('The identity must be a SHA-256 hash (64 lowercase hex characters), never a raw identifier.');
        }
    }
}
