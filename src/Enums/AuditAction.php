<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Enums;

/**
 * What an audit entry records (ADR-0015): the CLI's changes (SPEC-013), and the
 * JSON API's (SPEC-017).
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
}
