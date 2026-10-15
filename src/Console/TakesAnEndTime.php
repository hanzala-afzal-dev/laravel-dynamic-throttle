<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Carbon\CarbonInterface;
use Illuminate\Support\Facades\Date;
use InvalidArgumentException;

/**
 * --for and --forever, for the commands that turn a setting on until an end time
 * (SPEC-013).
 *
 * @internal
 */
trait TakesAnEndTime
{
    /**
     * The end time from --for or --forever, and an hour from now without either
     * (SPEC-013 decision 2).
     *
     * @throws InvalidArgumentException naming the mistake
     */
    protected function until(): ?CarbonInterface
    {
        $for = $this->option('for');
        $forever = $this->option('forever') === true;

        if ($this->forGiven() && $forever) {
            throw new InvalidArgumentException('Pass --for or --forever, not both.');
        }

        // --for without a value would otherwise mean one hour by mistake (the review).
        if ($this->forGiven() && ! is_string($for)) {
            throw new InvalidArgumentException('--for needs a duration, such as 30m.');
        }

        return $forever ? null : Date::now()->addSeconds(Duration::seconds(is_string($for) ? $for : '1h'));
    }

    /**
     * Turning a setting off takes no end time: null, or why not.
     *
     * @throws InvalidArgumentException
     */
    protected function noEndTime(): null
    {
        return ! $this->forGiven() && $this->option('forever') !== true
            ? null
            : throw new InvalidArgumentException('--for and --forever do not apply to off.');
    }

    /**
     * Whether --for was given, with a value or without one.
     */
    private function forGiven(): bool
    {
        return $this->option('for') !== null || $this->input->hasParameterOption('--for');
    }
}
