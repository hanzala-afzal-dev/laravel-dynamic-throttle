<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Support\ChangeOutcome;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Carbon\CarbonInterface;
use InvalidArgumentException;

/**
 * Lets every request through, unlimited, until an end time (ADR-0011, SPEC-013).
 */
final class BypassCommand extends ChangeCommand
{
    protected $signature = 'dynamic-throttle:bypass
        {state : on or off}
        {--for= : How long, such as 90s, 30m, 2h or 1d. One hour by default}
        {--forever : No end time}
        {--force : Do not ask for confirmation in production}';

    protected $description = 'Let every request through, unlimited, until an end time, or stop';

    public function handle(EmergencyControls $controls): int
    {
        $state = $this->argument('state');

        if ($state !== 'on' && $state !== 'off') {
            return $this->refuse('The state must be on or off, got "'.(is_string($state) ? $state : '').'".');
        }

        try {
            $until = $state === 'on' ? $this->until() : $this->noEndTime();
        } catch (InvalidArgumentException $exception) {
            return $this->refuse($exception->getMessage());
        }

        if ($state === 'off') {
            return $this->change(fn (Actor $actor): ChangeOutcome => $controls->endBypass($actor), 'Bypass is off: the rules apply again.', 'Bypass is already off.');
        }

        if (! $this->confirmed('Bypass lets every request through, unlimited')) {
            return self::FAILURE;
        }

        return $this->change(
            fn (Actor $actor): ChangeOutcome => $controls->bypass($until, $actor),
            $until instanceof CarbonInterface
                ? "Bypass is on until {$this->time($until)}: every request goes through, unlimited."
                : 'Bypass is on, with no end time: every request goes through, unlimited. Turn it off with php artisan dynamic-throttle:bypass off.',
            $until instanceof CarbonInterface ? "Bypass is already on until {$this->time($until)}." : 'Bypass is already on, with no end time.',
        );
    }
}
