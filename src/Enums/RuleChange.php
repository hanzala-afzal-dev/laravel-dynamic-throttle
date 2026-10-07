<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What happened to a rule, in RuleChanged (SPEC-015). Enabling and disabling a
 * rule are updates of its enabled attribute.
 */
enum RuleChange: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Deleted = 'deleted';
}
