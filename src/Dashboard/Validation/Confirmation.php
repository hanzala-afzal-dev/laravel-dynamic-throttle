<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\Engine\Exemptions\IpRange;
use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * The changes that loosen or tighten limits for everyone need "confirm": true, in
 * every environment, since the dashboard is always a person at a page (SPEC-018
 * decision 3, ADR-0015 point 4): disabling, deleting or dry-running an enforcing
 * rule, bypass on, a factor below 1, and an IP exemption wider than a /8 or a /32.
 * The CLI asks only in production (SPEC-013).
 *
 * @internal
 */
final class Confirmation
{
    /** The widest IPv4 range an exemption takes without confirm. */
    private const int WIDEST_IPV4 = 8;

    /** The widest IPv6 range an exemption takes without confirm. */
    private const int WIDEST_IPV6 = 32;

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
     * A change of an enabled, enforcing rule that turns it into a dry-run one, which then
     * answers no 429, or that disables it (the security review).
     *
     * @param  array<string, mixed>  $attributes  the rule's attributes after the change
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function ruleChanged(Rule $rule, array $attributes, array $input): void
    {
        if (array_key_exists('enabled', $attributes) && filter_var($attributes['enabled'], FILTER_VALIDATE_BOOL) === false) {
            self::ruleEnabled($rule, false, $input);

            return;
        }

        $mode = $attributes['mode'] ?? null;

        if (self::limits($rule) && ($mode instanceof RuleMode ? $mode : RuleMode::tryFrom(is_string($mode) ? $mode : '')) === RuleMode::DryRun) {
            self::require($input, "Rule {$rule->id} ({$rule->name}) stops limiting requests while it runs in dry-run.");
        }
    }

    /**
     * An IP exemption wider than a /8 for IPv4 or a /32 for IPv6, which frees much of the
     * internet: 0.0.0.0/0 and ::/0 together work as a bypass with no end time (the
     * security review).
     *
     * @param  array<string, mixed>  $attributes  the exemption's validated attributes
     * @param  array<string, mixed>  $input
     *
     * @throws ConfirmationRequired
     */
    public static function exemption(array $attributes, array $input): void
    {
        $value = $attributes['value'] ?? null;

        if (($attributes['subject'] ?? null) !== ExemptionSubject::Ip->value || ! is_string($value)) {
            return;
        }

        try {
            $range = IpRange::parse($value);
        } catch (InvalidArgumentException) {
            return;
        }

        // The network is 4 bytes for IPv4, and 16 for IPv6.
        if ($range->prefixLength >= (strlen($range->network) === 16 ? self::WIDEST_IPV6 : self::WIDEST_IPV4)) {
            return;
        }

        $ruleId = $attributes['rule_id'] ?? null;
        $rule = is_int($ruleId) ? Rule::query()->find($ruleId) : null;
        $from = $rule instanceof Rule ? "rule {$rule->id} ({$rule->name})" : 'every rule';

        self::require($input, "The exemption frees every client in {$value} from {$from}.");
    }

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
