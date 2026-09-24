<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * An enforcing rule rejects; a dry-run rule counts the same way but only
 * reports what it would have rejected (ADR-0012).
 */
enum RuleMode: string
{
    case Enforce = 'enforce';
    case DryRun = 'dry_run';
}
