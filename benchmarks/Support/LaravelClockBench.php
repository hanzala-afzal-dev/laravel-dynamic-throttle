<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Support;

use Afzal\DynamicThrottle\Support\LaravelClock;
use Illuminate\Container\Container;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use PhpBench\Attributes as Bench;

/**
 * The cache driver reads this clock once per attempt (ADR-0006). The Redis
 * driver reads Redis TIME inside its script instead.
 */
#[Bench\Revs(10_000)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods('setUp')]
final class LaravelClockBench
{
    private LaravelClock $clock;

    public function setUp(): void
    {
        $app = new Container;
        $app->instance('date', new DateFactory);
        Facade::setFacadeApplication($app);

        $this->clock = new LaravelClock;
    }

    public function benchMicroseconds(): void
    {
        $this->clock->microseconds();
    }
}
