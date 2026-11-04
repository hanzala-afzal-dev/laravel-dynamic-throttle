<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What an audit entry records (ADR-0015): the CLI's changes (SPEC-013), the JSON
 * API's (SPEC-017), and the dashboard's links, made, used and ended (SPEC-034).
 */
enum AuditAction: string
{
    case SettingsBypass = 'settings.bypass';
    case SettingsScale = 'settings.scale';
    case RuleDisable = 'rule.disable';
    case RuleEnable = 'rule.enable';
    case RuleCreate = 'rule.create';
    case RuleUpdate = 'rule.update';
    case RuleDelete = 'rule.delete';
    case ExemptionCreate = 'exemption.create';
    case ExemptionDelete = 'exemption.delete';
    case LinkCreate = 'link.create';
    case LinkUse = 'link.use';
    case LinkRevoke = 'link.revoke';
}
