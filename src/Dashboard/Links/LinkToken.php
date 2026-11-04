<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Links;

/**
 * A link's token (SPEC-034 decisions 5 and 7): 32 random bytes as 43 characters of
 * base64url, and its SHA-256, which is all the database keeps. The token has 256
 * random bits, so a fast hash is enough.
 *
 * @internal
 */
final class LinkToken
{
    public const string FORMAT = '/\A[A-Za-z0-9_-]{43}\z/';

    public static function make(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    public static function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
