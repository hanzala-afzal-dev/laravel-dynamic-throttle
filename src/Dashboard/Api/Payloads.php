<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Models\AuditLogEntry;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use stdClass;

/**
 * How the API shows rules, exemptions (SPEC-017) and audit entries (SPEC-019): every field it takes, enums
 * as their values, and times in ISO 8601, in UTC.
 *
 * @internal
 */
final class Payloads
{
    /**
     * @return array<string, mixed>
     */
    public static function rule(Rule $rule): array
    {
        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'description' => $rule->description,
            'method' => $rule->method->value,
            'pattern' => $rule->pattern,
            'scope' => $rule->scope->value,
            'scope_parameter' => $rule->scope_parameter,
            'max_attempts' => $rule->max_attempts,
            'window_seconds' => $rule->window_seconds,
            'priority' => $rule->priority,
            'mode' => $rule->mode->value,
            'on_failure' => $rule->on_failure->value,
            'on_missing_identity' => $rule->on_missing_identity->value,
            // The form sent {}, which the array cast reads back as [] (SPEC-022 AC-13).
            'response_override' => $rule->response_override === [] ? new stdClass : $rule->response_override,
            'enabled' => $rule->enabled,
            'created_at' => self::time($rule->created_at),
            'updated_at' => self::time($rule->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function exemption(Exemption $exemption): array
    {
        return [
            'id' => $exemption->id,
            'rule_id' => $exemption->rule_id,
            'subject' => $exemption->subject->value,
            'subject_parameter' => $exemption->subject_parameter,
            'value' => $exemption->value,
            'reason' => $exemption->reason,
            'expires_at' => self::time($exemption->expires_at),
            'active' => $exemption->expires_at === null || $exemption->expires_at->greaterThan(Date::now()),
            'created_at' => self::time($exemption->created_at),
            'updated_at' => self::time($exemption->updated_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function audit(AuditLogEntry $entry): array
    {
        return [
            'id' => $entry->id,
            'actor' => $entry->actor,
            'action' => $entry->action,
            'target' => $entry->target,
            'before' => $entry->before,
            'after' => $entry->after,
            'ip' => $entry->ip,
            'user_agent' => $entry->user_agent,
            'created_at' => self::time($entry->created_at),
        ];
    }

    public static function time(?CarbonInterface $time): ?string
    {
        return $time?->clone()->setTimezone('UTC')->toIso8601String();
    }
}
