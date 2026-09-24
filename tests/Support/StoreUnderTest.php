<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;

/**
 * A limiter store and the clock that drives it, for the contract suite.
 */
final readonly class StoreUnderTest
{
    public function __construct(
        public LimiterStore $store,
        public FakeClock $clock,
    ) {}
}
