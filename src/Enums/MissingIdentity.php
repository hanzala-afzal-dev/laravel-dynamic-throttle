<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What a rule does when its scope finds no identity, such as a guest for a
 * `user` rule: count the request under the scope's IP fallback, or skip the
 * rule for that request (ADR-0009, ADR-0022).
 */
enum MissingIdentity: string
{
    case FallBackToIp = 'ip';
    case Skip = 'skip';
}
