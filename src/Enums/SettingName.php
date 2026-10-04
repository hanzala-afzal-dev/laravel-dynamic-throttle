<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * The runtime settings an admin can switch during an incident (ADR-0011, SPEC-013).
 */
enum SettingName: string
{
    /** Every request goes through, unlimited. */
    case BypassAll = 'bypass_all';

    /** Every rule's limit is multiplied by the factor, from 0.1 to 10. */
    case ScaleFactor = 'scale_factor';
}
