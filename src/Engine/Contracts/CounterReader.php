<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\CounterReading;

/**
 * Reads a counter without counting (SPEC-014), for dynamic-throttle:explain.
 *
 * @internal
 */
interface CounterReader
{
    public function peek(CounterKey $key, int $maxAttempts, int $windowSeconds): CounterReading;
}
