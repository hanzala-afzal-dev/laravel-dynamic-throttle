<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * The HTTP method a rule matches (ADR-0008). `Any` matches every method.
 */
enum RequestMethod: string
{
    case Any = '*';
    case Get = 'GET';
    case Head = 'HEAD';
    case Post = 'POST';
    case Put = 'PUT';
    case Patch = 'PATCH';
    case Delete = 'DELETE';
    case Options = 'OPTIONS';
}
