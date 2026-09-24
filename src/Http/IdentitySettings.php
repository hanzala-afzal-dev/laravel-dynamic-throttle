<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

/**
 * The scopes config section, checked (SPEC-008).
 */
final readonly class IdentitySettings
{
    /**
     * @param  int  $ipv6Prefix  scopes.ip.ipv6_prefix: 1 to 128
     * @param  list<string>|null  $guards  scopes.user.guards: null for the guard the request authenticated with
     */
    public function __construct(
        public int $ipv6Prefix,
        public ?array $guards,
    ) {}
}
