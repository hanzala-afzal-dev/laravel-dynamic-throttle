<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Afzal\DynamicThrottle\Models\Rule;

/**
 * The rules' validation (SPEC-018). A stub until its implementation.
 *
 * @internal
 */
final class RuleInput
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function validate(array $input, ?Rule $rule = null): array
    {
        return [];
    }
}
