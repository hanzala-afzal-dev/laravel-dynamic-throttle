<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * Who a counter belongs to (ADR-0009). `Header` and `Custom` take a parameter:
 * the header name, or the name of a resolver the app registers.
 */
enum Scope: string
{
    case Ip = 'ip';
    case User = 'user';
    case ApiToken = 'api_token';
    case Header = 'header';
    case Global = 'global';
    case Custom = 'custom';
}
