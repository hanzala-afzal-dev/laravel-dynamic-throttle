<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\Models\Rule;
use Carbon\CarbonInterface;

/**
 * The dangerous changes' confirmation (SPEC-018). A stub until its implementation.
 *
 * @internal
 */
final class Confirmation
{
    /**
     * @param  array<string, mixed>  $input
     */
    public static function ruleEnabled(Rule $rule, bool $enabled, array $input): void {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function ruleDeleted(Rule $rule, array $input): void {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function bypass(bool $on, ?CarbonInterface $until, array $input): void {}

    /**
     * @param  array<string, mixed>  $input
     */
    public static function scale(?int $percent, ?CarbonInterface $until, array $input): void {}
}
