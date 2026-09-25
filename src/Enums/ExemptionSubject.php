<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * The client an exemption frees from rate limits (SPEC-009 decision 1): an IP
 * address or range, a user, or a value of a custom scope, whose name is the
 * exemption's parameter.
 */
enum ExemptionSubject: string
{
    case Ip = 'ip';
    case User = 'user';
    case Custom = 'custom';
}
