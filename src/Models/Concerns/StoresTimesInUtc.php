<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;

/**
 * The package's tables store every time in UTC, whatever the app's time zone, so a
 * daylight-saving change cannot move an end time by the repeated hour (the Phase 3
 * review, SPEC-009 AC-24, SPEC-013 AC-26). A date given in any zone keeps its
 * instant, and reads back in the app's zone.
 */
trait StoresTimesInUtc
{
    /**
     * Reads the date as Eloquent does, a text in the app's zone, then stores it in UTC.
     */
    public function fromDateTime(mixed $value): mixed
    {
        return $value === null || $value === '' ? $value : parent::fromDateTime(parent::asDateTime($value)->setTimezone('UTC'));
    }

    /**
     * A stored date is a text in UTC. It reads back in the app's zone.
     *
     * @return Carbon
     */
    protected function asDateTime(mixed $value)
    {
        if (is_string($value) && ! is_numeric($value)) {
            return parent::asDateTime(Date::parse($value, 'UTC')->setTimezone(date_default_timezone_get()));
        }

        return parent::asDateTime($value);
    }
}
