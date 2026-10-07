<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Support\Str;

/**
 * The compiled manifest requests read (SPEC-016 AC-12). Without one, no rule
 * applies (ADR-0010 point 3). It is stale when the scheduled compile, which
 * rewrites it every 5 minutes with a new version, has not for 10 (SPEC-006,
 * decision 11). And every load of it pays for each rule and exemption it holds
 * (ADR-0007's fallback, SPEC-009 decision 4).
 *
 * @internal
 */
final readonly class ManifestCheck implements Check
{
    /** Two scheduled compiles missed, in seconds. */
    private const int STALE_SECONDS = 600;

    private const int MAX_RULES = 500;

    private const int MAX_EXEMPTIONS = 1_000;

    public function id(): string
    {
        return 'manifest';
    }

    public function run(HealthContext $context): CheckResult
    {
        $read = $context->manifest();

        if (! $read->manifest instanceof Manifest) {
            return CheckResult::error($this->id(), $read->error === null
                ? 'The manifest is missing, so no rule applies: run php artisan dynamic-throttle:compile.'
                : "The manifest cannot be read, so no rule applies: {$read->error}");
        }

        $manifest = $read->manifest;
        // The version is the compile's time in milliseconds, unless a clock ran ahead (SPEC-006 AC-3).
        $compiled = intdiv($manifest->version, 1_000);
        $age = max(0, $context->now() - $compiled);
        $minutes = intdiv($age, 60);
        $rules = count($manifest->rules());
        $exemptions = count($manifest->exemptions->all());
        $summary = "Version {$manifest->version}, compiled at ".HealthContext::time($compiled).", {$minutes} ".Str::plural('minute', $minutes).' ago: '
            .number_format($rules).' '.Str::plural('rule', $rules).', '.number_format($exemptions).' '.Str::plural('exemption', $exemptions).'.';
        $problems = [];

        if ($age > self::STALE_SECONDS && $context->config->get('dynamic-throttle.manifest.schedule') !== false) {
            $problems[] = 'The scheduled compile rewrites it every 5 minutes, so the scheduler is not running, or the compile fails: check that the scheduler runs php artisan schedule:run every minute, and run php artisan dynamic-throttle:compile to see.';
        }

        if ($rules > self::MAX_RULES || $exemptions > self::MAX_EXEMPTIONS) {
            $problems[] = 'Every load of the manifest pays for each rule and exemption it holds, which is more than 500 rules or 1,000 exemptions: on PHP-FPM without APCu, every request loads it. Disable the rules you do not use, and delete expired exemptions.';
        }

        return $problems === []
            ? CheckResult::ok($this->id(), $summary)
            : CheckResult::warning($this->id(), $summary.' '.implode(' ', $problems));
    }
}
