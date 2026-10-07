<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use InvalidArgumentException;
use Random\Randomizer;

/**
 * Picks the requests telemetry records, and how many requests each stands for
 * (SPEC-015 decision 6). At rate p, a request is picked when a draw falls below
 * p, and then weighs ⌊1/p⌋, or ⌈1/p⌉ with probability equal to the fractional
 * part of 1/p, which a second draw decides. So the weights add up to the
 * requests made, on average, whatever the rate.
 *
 * @internal
 */
final readonly class Sampler
{
    /** The heaviest weight: 2^53, which an integer and a float both hold exactly. */
    private const float MAX_WEIGHT = 2.0 ** 53;

    /**
     * @throws InvalidArgumentException for a rate outside 0 to 1
     */
    public function __construct(
        /** telemetry.sample_rate, from 0 to 1. */
        public float $rate,
        private Randomizer $randomizer = new Randomizer,
    ) {
        if (is_nan($rate) || $rate < 0.0 || $rate > 1.0) {
            throw new InvalidArgumentException("The sample rate must be from 0 to 1, got {$rate}.");
        }
    }

    /**
     * 0 when the request is not recorded, else how many requests it stands for.
     */
    public function weight(): int
    {
        // The default rate, which needs no draw.
        if ($this->rate === 1.0) {
            return 1;
        }

        // A draw is never below 0, so the rate 0 picks nothing.
        if ($this->randomizer->nextFloat() >= $this->rate) {
            return 0;
        }

        $inverse = min(1 / $this->rate, self::MAX_WEIGHT);
        $whole = floor($inverse);
        $fraction = $inverse - $whole;

        return (int) $whole + ($fraction > 0.0 && $this->randomizer->nextFloat() < $fraction ? 1 : 0);
    }
}
