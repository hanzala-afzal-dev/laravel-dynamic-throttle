<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;

covers(ProcessFailureMemory::class);

/*
| SPEC-012: the failures a process remembers. A long-lived worker keeps only
| the causes reported within their cooldown, so the list never grows.
*/

it('forgets a reported cause once its cooldown has passed, when it marks another', function (): void {
    $memory = new ProcessFailureMemory;
    $t0 = FakeClock::T0 * 1_000_000;
    $memory->markReported('a', $t0, 10);

    $memory->markReported('b', $t0 + 9_999_999, 10);
    $justBefore = $memory->reportedAt('a');
    $memory->markReported('c', $t0 + 10_000_000, 10);

    expect($justBefore)->toBe($t0)
        ->and([$memory->reportedAt('a'), $memory->reportedAt('b'), $memory->reportedAt('c')])
        ->toBe([null, $t0 + 9_999_999, $t0 + 10_000_000]);
});

it('keeps when the cooldown started, and starts it again', function (): void {
    $memory = new ProcessFailureMemory;
    $t0 = FakeClock::T0 * 1_000_000;
    $before = $memory->cooldownStartedAt();

    $memory->startCooldown($t0, 10);
    $memory->startCooldown($t0 + 5_000_000, 10);

    expect($before)->toBeNull()
        ->and($memory->cooldownStartedAt())->toBe($t0 + 5_000_000);
});
