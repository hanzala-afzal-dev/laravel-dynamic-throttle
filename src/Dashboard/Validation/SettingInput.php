<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Carbon\CarbonInterface;

/**
 * The settings' validation (SPEC-018). A stub until its implementation.
 *
 * @internal
 */
final class SettingInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array{on: bool, until: ?CarbonInterface}
     */
    public function bypass(array $input): array
    {
        return ['on' => false, 'until' => null];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{percent: ?int, until: ?CarbonInterface}
     */
    public function scale(array $input): array
    {
        return ['percent' => null, 'until' => null];
    }
}
