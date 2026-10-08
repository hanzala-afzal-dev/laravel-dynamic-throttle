<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * User rules that saw mostly guests in the last hour, counted under their IP or
 * let through by a skip rule (SPEC-016 AC-16, ADR-0004 point 6, ADR-0022). That
 * usually means authentication the route check cannot see. Half or more of at
 * least 100 matched requests recorded (decision 5): below a sample rate of 1,
 * the requests they stand for would let a handful of samples warn.
 *
 * @internal
 */
final readonly class GuestsCheck implements Check
{
    /** Fewer matched requests recorded tell nothing. */
    private const int MIN_REQUESTS = 100;

    public function id(): string
    {
        return 'guests';
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

        $users = array_filter($rules, fn (CompiledRule $rule): bool => $rule->scope === Scope::User);

        if ($users === []) {
            return CheckResult::ok($this->id(), 'There is no user rule.');
        }

        $found = [];
        $rate = ConfigValues::sampleRate($context->config->get('dynamic-throttle.telemetry.sample_rate'));

        foreach ($users as $rule) {
            $counts = $numbers[$rule->id] ?? null;

            if ($counts instanceof MinuteCounts && ($sentence = $this->mostlyGuests($rule, $counts, $rate)) !== null) {
                $found[] = $sentence;
            }
        }

        return $found === []
            ? CheckResult::ok($this->id(), 'No user rule saw mostly guests in the last hour.')
            : CheckResult::warning($this->id(), implode(' ', $found).' Authentication probably runs after dynamic.throttle on their routes, in a middleware the package does not recognize, which dynamic-throttle.middleware.after can name. Or the rules match public routes, where an IP rule fits better.');
    }

    /**
     * What a user rule saw, when guests were half or more of what it matched: the
     * requests it checked, and those it let through without a user. The numbers
     * are what the recorded requests stand for, at the sample rate $rate.
     */
    private function mostlyGuests(CompiledRule $rule, MinuteCounts $counts, float $rate): ?string
    {
        $matched = $counts->hits + $counts->skipped;
        $guests = $counts->guests + $counts->skipped;

        if ((int) round($matched * $rate) < self::MIN_REQUESTS || $guests * 2 < $matched) {
            return null;
        }

        $share = (int) round($guests * 100 / $matched);
        $saw = $rule->onMissingIdentity === MissingIdentity::Skip ? 'let' : 'counted';
        $as = $rule->onMissingIdentity === MissingIdentity::Skip ? 'pass as guests' : 'as guests';

        return ucfirst(HealthContext::rule($rule)).", {$saw} ".number_format($guests).' of '.number_format($matched)." requests {$as} in the last hour ({$share}%).";
    }
}
