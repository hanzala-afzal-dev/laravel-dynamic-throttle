<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Afzal\DynamicThrottle\Support\ScaleFactor;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Multiplies every rule's limit by a factor, until an end time (ADR-0011, SPEC-013).
 */
final class ScaleCommand extends ChangeCommand
{
    protected $signature = 'dynamic-throttle:scale
        {factor : From 0.1 to 10, with at most two decimals, such as 1.5, or off}
        {--for= : How long, such as 90s, 30m, 2h or 1d. One hour by default}
        {--forever : No end time}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Multiply every rule\'s limit by a factor until an end time, or stop';

    public function handle(EmergencyControls $controls): int
    {
        $factor = $this->argument('factor');
        $factor = is_string($factor) ? $factor : '';

        try {
            $percent = $factor === 'off' ? null : ScaleFactor::percent($factor);
            $until = $percent === null ? $this->noEndTime() : $this->until();
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        if ($percent === null) {
            return $this->change(fn (Actor $actor): ChangeOutcome => $controls->endScale($actor), 'Limits are no longer scaled.', 'Limits are not scaled.');
        }

        // A factor below 1 lowers every limit, a dangerous action (ADR-0015 point 4).
        if ($percent < 100 && ! $this->confirmed('A factor below 1 lowers every limit')) {
            return self::FAILURE;
        }

        $shown = ScaleFactor::format($percent);

        return $this->change(
            fn (Actor $actor): ChangeOutcome => $controls->scale($percent, $until, $actor),
            $until instanceof CarbonInterface
                ? "Limits are scaled by {$shown} until {$this->time($until)}."
                : "Limits are scaled by {$shown}, with no end time. Turn it off with php artisan dynamic-throttle:scale off.",
            $until instanceof CarbonInterface ? "Limits are already scaled by {$shown} until {$this->time($until)}." : "Limits are already scaled by {$shown}, with no end time.",
        );
    }
}
