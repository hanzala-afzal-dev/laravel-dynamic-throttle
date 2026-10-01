<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What an audit entry records (ADR-0015, SPEC-013). SPEC-019 adds the JSON API's
 * and the dashboard's actions.
 */
enum AuditAction: string
{
    case SettingsBypass = 'settings.bypass';
    case SettingsScale = 'settings.scale';
    case RuleDisable = 'rule.disable';
    case RuleEnable = 'rule.enable';
}
