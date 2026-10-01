<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\EmergencyControls;

/**
 * Switches one rule off, the recovery path when a bad rule blocks traffic and the
 * dashboard is unreachable (ADR-0015 point 6, SPEC-013).
 */
final class DisableRuleCommand extends ChangeCommand
{
    protected $signature = 'dynamic-throttle:disable-rule
        {id : The rule\'s id, as dynamic-throttle:list shows it}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Switch a rule off, so it limits no request';

    public function handle(EmergencyControls $controls): int
    {
        $rule = $this->rule();

        if (! $rule instanceof Rule) {
            return $this->refuse("There is no rule {$this->id()}.");
        }

        $label = "Rule {$rule->id} ({$rule->name})";

        // Disabling an enforcing rule is a dangerous action (ADR-0015 point 4).
        if ($rule->enabled && $rule->mode === RuleMode::Enforce && ! $this->confirmed("{$label} stops limiting requests")) {
            return self::FAILURE;
        }

        return $this->change(fn (string $actor): ChangeOutcome => $controls->setRuleEnabled($rule, false, $actor), "{$label} is disabled.", "{$label} is already disabled.");
    }
}
