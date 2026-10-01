<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\EmergencyControls;

/**
 * Switches one rule back on (ADR-0015 point 6, SPEC-013).
 */
final class EnableRuleCommand extends ChangeCommand
{
    protected $signature = 'dynamic-throttle:enable-rule
        {id : The rule\'s id, as dynamic-throttle:list shows it}';

    protected $description = 'Switch a rule back on';

    public function handle(EmergencyControls $controls): int
    {
        $rule = $this->rule();

        if (! $rule instanceof Rule) {
            return $this->refuse("There is no rule {$this->id()}.");
        }

        $label = "Rule {$rule->id} ({$rule->name})";

        return $this->change(fn (string $actor): ChangeOutcome => $controls->setRuleEnabled($rule, true, $actor), "{$label} is enabled.", "{$label} is already enabled.");
    }
}
