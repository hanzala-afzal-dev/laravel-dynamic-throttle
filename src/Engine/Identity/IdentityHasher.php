<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

/**
 * Hashes identities before they reach a key (ADR-0009): HMAC-SHA-256 with a
 * key derived from APP_KEY, so a cache dump cannot be reversed by hashing
 * every IP or id (ADR-0024). Without an app key it falls back to plain
 * SHA-256, so limits keep working.
 */
final readonly class IdentityHasher
{
    /** Changing it changes every hash, and so resets every counter (ADR-0024). */
    private const string KEY_CONTEXT = 'dynamic-throttle:identity:v1';

    private ?string $key;

    public function __construct(?string $appKey)
    {
        $this->key = $appKey === null || $appKey === '' ? null : hash_hmac('sha256', self::KEY_CONTEXT, $appKey, true);
    }

    /**
     * @return string 64 lowercase hex characters
     */
    public function hash(string $identity): string
    {
        return $this->key === null ? hash('sha256', $identity) : hash_hmac('sha256', $identity, $this->key);
    }
}
