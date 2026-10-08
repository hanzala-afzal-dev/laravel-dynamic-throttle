<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Support\Str;

/**
 * Rules ignored in the last hour because more than matching.max_rules_per_request
 * rules of their mode matched a request (SPEC-016 AC-18, ADR-0008, ADR-0025).
 * Any is too many (decision 5).
 *
 * @internal
 */
final readonly class IgnoredCheck implements Check
{
    public function id(): string
    {
        return 'ignored';
    }

    public function run(HealthContext $context): CheckResult
    {
        $off = $context->telemetryOff();
        $rules = $context->rules();

        if ($off !== null || is_string($rules)) {
            return CheckResult::skipped($this->id(), $off ?? (string) $rules);
        }

        $numbers = $context->lastHour();

        if (is_string($numbers)) {
            return CheckResult::skipped($this->id(), $numbers);
        }

        $ignored = [];

        foreach ($rules as $rule) {
            $counts = $numbers[$rule->id] ?? null;
            $times = $counts instanceof MinuteCounts ? $counts->ignored : 0;

            if ($times > 0) {
                $mode = $rule->mode === RuleMode::Enforce ? 'enforcing' : 'in dry run';
                $ignored[] = ucfirst(HealthContext::rule($rule)).", {$mode}, ignored for ".number_format($times).' '.Str::plural('request', $times);
            }
        }

        if ($ignored === []) {
            return CheckResult::ok($this->id(), 'No rule was ignored in the last hour.');
        }

        $cap = ConfigValues::maxRulesPerRequest($context->config->get('dynamic-throttle.matching.max_rules_per_request'));

        return CheckResult::warning($this->id(), HealthContext::listed($ignored, '; ')." in the last hour: more than dynamic-throttle.matching.max_rules_per_request, {$cap}, rules of its mode matched those requests, so the rest were ignored. Raise the cap, or narrow the rules.");
    }
}
