<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * Whether the scheduled flush keeps up (SPEC-016 AC-13, ADR-0013's fallback): the
 * counter store keeps the numbers for 2 hours only.
 *
 * @internal
 */
final readonly class MetricsCheck implements Check
{
    /** How far behind the last minute flushed may be, in minutes (ADR-0013). */
    private const int MAX_BEHIND = 10;

    public function id(): string
    {
        return 'metrics';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->config->get('dynamic-throttle.telemetry.enabled') === false) {
            return CheckResult::skipped($this->id(), 'dynamic-throttle.telemetry.enabled is off, so there is nothing to flush.');
        }

        try {
            $flushed = $context->buffer()->flushedThrough();
        } catch (StorageUnavailableException $exception) {
            return CheckResult::error($this->id(), 'The counter store cannot be read: '.HealthContext::message($exception));
        }

        if ($flushed === null) {
            return CheckResult::warning($this->id(), 'The metrics have not been flushed in the last 2 hours, which is as long as the counter store keeps them: check that the scheduler runs php artisan schedule:run every minute, which runs php artisan dynamic-throttle:flush-metrics.');
        }

        $behind = intdiv($context->now(), 60) - $flushed;
        $through = HealthContext::time($flushed * 60, 'Y-m-d H:i');

        return $behind > self::MAX_BEHIND
            ? CheckResult::warning($this->id(), "The metrics were last flushed through {$through}, {$behind} minutes ago, so the scheduler is not running, or the flush fails: check that the scheduler runs php artisan schedule:run every minute, and run php artisan dynamic-throttle:flush-metrics to see.")
            : CheckResult::ok($this->id(), "The metrics are flushed through {$through}.");
    }
}
