<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Console\PruneCommand;
use Closure;

/**
 * The value the package uses for each config key it falls back on, from what the
 * app's config holds there. The service provider builds the package with them,
 * and dynamic-throttle:health names each value they replace (SPEC-016 decision
 * 14), so the two never disagree.
 *
 * @internal
 */
final class ConfigValues
{
    /**
     * Each key under dynamic-throttle. that falls back, with the value it gives for
     * what the config holds, in the config file's order.
     *
     * @return array<string, Closure(mixed): mixed>
     */
    public static function all(): array
    {
        return [
            'manifest.refresh_seconds' => self::refreshSeconds(...),
            'matching.max_rules_per_request' => self::maxRulesPerRequest(...),
            'scopes.ip.ipv6_prefix' => self::ipv6Prefix(...),
            'scopes.user.guards' => self::guards(...),
            'telemetry.sample_rate' => self::sampleRate(...),
            'telemetry.retention_hours' => self::retentionHours(...),
            'failure.cooldown_seconds' => self::cooldownSeconds(...),
        ];
    }

    /**
     * manifest.refresh_seconds: 2, unless a whole number of at least 0.
     */
    public static function refreshSeconds(mixed $seconds): int
    {
        return is_int($seconds) && $seconds >= 0 ? $seconds : 2;
    }

    /**
     * matching.max_rules_per_request: 5, unless a whole number of at least 1.
     */
    public static function maxRulesPerRequest(mixed $cap): int
    {
        return is_int($cap) && $cap >= 1 ? $cap : 5;
    }

    /**
     * failure.cooldown_seconds: 10, unless a whole number of at least 0.
     */
    public static function cooldownSeconds(mixed $seconds): int
    {
        return is_int($seconds) && $seconds >= 0 ? $seconds : 10;
    }

    /**
     * telemetry.sample_rate, from 0 to 1. Anything else records every request, as the
     * default does.
     */
    public static function sampleRate(mixed $rate): float
    {
        return (is_int($rate) || is_float($rate)) && $rate >= 0 && $rate <= 1 ? (float) $rate : 1.0;
    }

    /**
     * telemetry.retention_hours, the hours the scheduled prune keeps: 720, 30 days,
     * unless a whole number of at least 1, and at most the prune's maximum.
     */
    public static function retentionHours(mixed $hours): int
    {
        return is_int($hours) && $hours >= 1 ? min($hours, PruneCommand::MAX_HOURS) : 720;
    }

    /**
     * scopes.ip.ipv6_prefix: 64, unless a whole number from 1 to 128.
     */
    public static function ipv6Prefix(mixed $prefix): int
    {
        return is_int($prefix) && $prefix >= 1 && $prefix <= 128 ? $prefix : 64;
    }

    /**
     * scopes.user.guards: a non-empty list of guard names, or null for the guard the
     * request authenticated with. Anything else counts as null.
     *
     * @return list<string>|null
     */
    public static function guards(mixed $guards): ?array
    {
        if (! is_array($guards) || $guards === [] || ! array_is_list($guards)) {
            return null;
        }

        $names = array_filter($guards, fn (mixed $guard): bool => is_string($guard) && $guard !== '');

        return count($names) === count($guards) ? array_values($names) : null;
    }
}
