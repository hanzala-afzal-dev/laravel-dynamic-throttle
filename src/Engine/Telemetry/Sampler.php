<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use LogicException;
use Random\Randomizer;

/**
 * Picks the requests telemetry records, and how many requests each stands for
 * (SPEC-015 decision 6).
 *
 * @internal
 */
final readonly class Sampler
{
    public function __construct(
        /** telemetry.sample_rate, from 0 to 1. */
        public float $rate,
        private Randomizer $randomizer = new Randomizer,
    ) {}

    /**
     * 0 when the request is not recorded, else how many requests it stands for.
     */
    public function weight(): int
    {
        throw new LogicException('SPEC-015: not built yet.');
    }
}
