<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\ScaleFactor;

/**
 * An emergency setting left on (SPEC-029 AC-5, decision 2): bypass or a scale
 * factor that servers apply with no end time, or with one more than a day
 * away. One that ends within a day is ok, so a deploy during an incident does
 * not fail on it. It reads the manifest servers read, as status does.
 *
 * @internal
 */
final readonly class EmergencyCheck implements Check
{
    /** A setting that ends later than this, or never, warns. */
    private const int WITHIN_SECONDS = 86_400;

    public function id(): string
    {
        return 'emergency';
    }

    public function run(HealthContext $context): CheckResult
    {
        $manifest = $context->manifest()->manifest;

        if (! $manifest instanceof Manifest) {
            return CheckResult::skipped($this->id(), 'Without a manifest, servers apply no setting: see the manifest check.');
        }

        $now = $context->now();
        $settings = $manifest->settings->at($now);
        $applied = [];

        if ($settings->bypass) {
            $applied[] = ['Bypass', $settings->bypassUntil, 'bypass off'];
        }

        if ($settings->scale !== null) {
            $applied[] = ['The scale factor '.ScaleFactor::format($settings->scale), $settings->scaleUntil, 'scale off'];
        }

        if ($applied === []) {
            return CheckResult::ok($this->id(), 'No emergency setting applies.');
        }

        $lines = [];
        $lasting = false;

        foreach ($applied as [$setting, $until, $off]) {
            $lasts = $until === null || $until - $now > self::WITHIN_SECONDS;
            $lasting = $lasting || $lasts;
            $lines[] = $this->line($setting, $until, $off, $lasts);
        }

        return $lasting
            ? CheckResult::warning($this->id(), implode(' ', $lines))
            : CheckResult::ok($this->id(), implode(' ', $lines));
    }

    /**
     * What applies, until when, and for a setting that lasts, how to end it.
     *
     * @param  ?int  $until  Unix seconds, or null for no end time
     */
    private function line(string $setting, ?int $until, string $off, bool $lasts): string
    {
        $time = $until === null ? null : HealthContext::time($until);

        if (! $lasts) {
            return "{$setting} applies until {$time}.";
        }

        $end = " end it with php artisan dynamic-throttle:{$off} once the emergency is over.";

        return $time === null
            ? "{$setting} applies with no end time:{$end}"
            : "{$setting} applies until {$time}, more than a day away:{$end}";
    }
}
