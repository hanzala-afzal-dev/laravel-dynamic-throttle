<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * User rules that saw mostly guests in the last hour, counted under their IP or
 * let through by a skip rule (SPEC-016 AC-16, ADR-0004 point 6, ADR-0022). That
 * usually means authentication the route check cannot see. Half or more of at
 * least 100 matched requests (decision 5).
 *
 * @internal
 */
final readonly class GuestsCheck implements Check
{
    /** Fewer matched requests tell nothing. */
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

        foreach ($users as $rule) {
            $counts = $numbers[$rule->id] ?? null;

            if ($counts instanceof MinuteCounts && ($sentence = $this->mostlyGuests($rule, $counts)) !== null) {
                $found[] = $sentence;
            }
        }

        return $found === []
            ? CheckResult::ok($this->id(), 'No user rule saw mostly guests in the last hour.')
            : CheckResult::warning($this->id(), implode(' ', $found).' Authentication probably runs after dynamic.throttle on their routes, in a middleware the package does not recognize, which dynamic-throttle.middleware.after can name. Or the rules match public routes, where an IP rule fits better.');
    }

    /**
     * What a user rule saw, when guests were half or more of what it matched: the
     * requests it checked, and those it let through without a user.
     */
    private function mostlyGuests(CompiledRule $rule, MinuteCounts $counts): ?string
    {
        $matched = $counts->hits + $counts->skipped;
        $guests = $counts->guests + $counts->skipped;

        if ($matched < self::MIN_REQUESTS || $guests * 2 < $matched) {
            return null;
        }

        $share = (int) round($guests * 100 / $matched);
        $saw = $rule->onMissingIdentity === MissingIdentity::Skip ? 'let' : 'counted';
        $as = $rule->onMissingIdentity === MissingIdentity::Skip ? 'pass as guests' : 'as guests';

        return ucfirst(HealthContext::rule($rule)).", {$saw} ".number_format($guests).' of '.number_format($matched)." requests {$as} in the last hour ({$share}%).";
    }
}
