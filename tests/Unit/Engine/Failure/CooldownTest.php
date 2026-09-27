<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;

covers(Cooldown::class, ProcessFailureMemory::class);

/*
| SPEC-012: the cooldown after a storage failure (ADR-0010 point 4), timed by
| the Clock. A FakeClock at T0, and a 10-second cooldown unless stated.
*/

/**
 * Moves a FakeClock to T0 plus some microseconds.
 */
function cooldownAt(FakeClock $clock, int $microseconds): void
{
    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 + $microseconds);
}

it('runs for its whole length from the moment it starts, in whole seconds rounded up', function (): void {
    $clock = new FakeClock;
    $cooldown = new Cooldown($clock, 10, new ProcessFailureMemory);
    $before = $cooldown->secondsLeft();

    $cooldown->start();
    $left = [];

    foreach ([0, 3_000_000, 9_500_000, 9_999_999, 10_000_000, 60_000_000] as $elapsed) {
        cooldownAt($clock, $elapsed);
        $left[] = $cooldown->secondsLeft();
    }

    expect($before)->toBe(0)
        ->and($left)->toBe([10, 7, 1, 1, 0, 0]);
});

it('ends when the clock goes back before its start, so a clock step never stretches it', function (): void {
    $clock = new FakeClock;
    $cooldown = new Cooldown($clock, 10, new ProcessFailureMemory);
    $cooldown->start();

    cooldownAt($clock, -1);

    expect($cooldown->secondsLeft())->toBe(0);
});

it('never runs when its length is 0', function (): void {
    $cooldown = new Cooldown(new FakeClock, 0, new ProcessFailureMemory);

    $cooldown->start();

    expect($cooldown->secondsLeft())->toBe(0);
});

it('starts again from the latest failure', function (): void {
    $clock = new FakeClock;
    $cooldown = new Cooldown($clock, 10, new ProcessFailureMemory);
    $cooldown->start();
    cooldownAt($clock, 5_000_000);
    $cooldown->start();

    cooldownAt($clock, 12_000_000);

    expect($cooldown->secondsLeft())->toBe(3);
});

it('keeps its length, for the event', function (): void {
    expect((new Cooldown(new FakeClock, 10, new ProcessFailureMemory))->seconds)->toBe(10);
});

it('AC-10: is seen by another process through the memory they share', function (): void {
    $clock = new FakeClock;
    $shared = new ProcessFailureMemory;
    $first = new Cooldown($clock, 10, new ProcessFailureMemory, $shared);
    $second = new Cooldown($clock, 10, new ProcessFailureMemory, $shared);

    $first->start();
    cooldownAt($clock, 4_000_000);

    expect($second->secondsLeft())->toBe(6);
});

it('AC-10: follows the latest start among its memories', function (): void {
    $clock = new FakeClock;
    $shared = new ProcessFailureMemory;
    $here = new Cooldown($clock, 10, new ProcessFailureMemory, $shared);
    $elsewhere = new Cooldown($clock, 10, new ProcessFailureMemory, $shared);
    $here->start();
    cooldownAt($clock, 5_000_000);
    $elsewhere->start();

    cooldownAt($clock, 12_000_000);

    // This process started at T0, but another one failed again at T0 + 5.
    expect($here->secondsLeft())->toBe(3);
});

it('writes nothing to its memories when its length is 0', function (): void {
    $memory = new ProcessFailureMemory;

    (new Cooldown(new FakeClock, 0, $memory))->start();

    expect($memory->cooldownStartedAt())->toBeNull();
});

it('runs for a whole second when its length is 1', function (): void {
    $clock = new FakeClock;
    $cooldown = new Cooldown($clock, 1, new ProcessFailureMemory);
    $cooldown->start();
    $left = [];

    foreach ([0, 999_999, 1_000_000] as $elapsed) {
        cooldownAt($clock, $elapsed);
        $left[] = $cooldown->secondsLeft();
    }

    expect($left)->toBe([1, 1, 0]);
});
