<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;

/**
 * Switches one rule back on (ADR-0015 point 6, SPEC-013).
 */
final class EnableRuleCommand extends ChangeCommand
{
    protected $signature = 'dynamic-throttle:enable-rule
        {id : The rule\'s id, as dynamic-throttle:list shows it}
        {--force : Accepted, so scripts can pass it to every command: enabling a rule never asks}';

    protected $description = 'Switch a rule back on';

    public function handle(EmergencyControls $controls): int
    {
        $rule = $this->ruleOrRefusal();

        if (! $rule instanceof Rule) {
            return $rule;
        }

        $label = $this->label($rule);

        return $this->change(fn (Actor $actor): ChangeOutcome => $controls->setRuleEnabled($rule, true, $actor), "{$label} is enabled.", "{$label} is already enabled.");
    }
}
