<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What a rule does when its counter cannot be checked (ADR-0010): allow the
 * request, or answer 503.
 */
enum FailurePolicy: string
{
    case Open = 'open';
    case Closed = 'closed';
}
