<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Carbon\CarbonInterface;

/**
 * The changes that loosen or tighten limits for everyone need "confirm": true, in
 * every environment, since the dashboard is always a person at a page (SPEC-018
 * decision 3, ADR-0015 point 4): disabling or deleting an enforcing rule, bypass
 * on, and a factor below 1. The CLI asks only in production (SPEC-013).
 *
 * @internal
 */
final class Confirmation
{
    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function ruleEnabled(Rule $rule, bool $enabled, array $input): void
    {
        if (! $enabled && self::limits($rule)) {
            self::require($input, "Rule {$rule->id} ({$rule->name}) stops limiting requests until it is enabled again.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function ruleDeleted(Rule $rule, array $input): void
    {
        if (self::limits($rule)) {
            self::require($input, "Rule {$rule->id} ({$rule->name}) is deleted, and stops limiting requests.");
        }
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function bypass(bool $on, ?CarbonInterface $until, array $input): void
    {
        if ($on) {
            self::require($input, 'Bypass lifts every limit'.self::until($until).'.');
        }
    }

    /**
     * @param  ?int  $percent  the factor as a percentage, null to turn it off
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function scale(?int $percent, ?CarbonInterface $until, array $input): void
    {
        if ($percent !== null && $percent < 100) {
            self::require($input, 'Every limit is multiplied by '.ScaleFactor::format($percent).self::until($until).', so clients are limited sooner.');
        }
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $input
     */
    public static function ruleChanged(Rule $rule, array $attributes, array $input): void {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<string, mixed>  $input
     */
    public static function exemption(array $attributes, array $input): void {}

    /**
     * An enabled, enforcing rule, which answers 429s.
     */
    private static function limits(Rule $rule): bool
    {
        return $rule->enabled && $rule->mode === RuleMode::Enforce;
    }

    private static function until(?CarbonInterface $until): string
    {
        return $until instanceof CarbonInterface
            ? ' until '.$until->clone()->setTimezone('UTC')->format('Y-m-d H:i').' UTC'
            : ', with no end time';
    }

    /**
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    private static function require(array $input, string $consequence): void
    {
        if (! filter_var($input['confirm'] ?? false, FILTER_VALIDATE_BOOL)) {
            throw new ConfirmationRequired($consequence);
        }
    }
}
