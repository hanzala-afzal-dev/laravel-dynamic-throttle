<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;

covers(ReportThrottle::class, ProcessFailureMemory::class);

/*
| SPEC-012 decision 5: each cause is reported at most once per cooldown. A
| cause is the class and the throw site of the root exception. A FakeClock at
| T0, and a 10-second cooldown unless stated.
*/

/**
 * An exception thrown on one line, whatever its message: one cause.
 */
function oneCause(string $message): RuntimeException
{
    return new RuntimeException($message);
}

it('reports a cause once per cooldown, whatever its message', function (): void {
    $clock = new FakeClock;
    $throttle = new ReportThrottle($clock, 10, new ProcessFailureMemory);

    $first = [$throttle->allows(oneCause('Tenant 1 failed.')), $throttle->allows(oneCause('Tenant 2 failed.'))];
    $clock->setMicroseconds((FakeClock::T0 + 10) * 1_000_000 - 1);
    $justBefore = $throttle->allows(oneCause('Tenant 3 failed.'));
    $clock->setSeconds(FakeClock::T0 + 10);

    expect($first)->toBe([true, false])
        ->and($justBefore)->toBeFalse()
        ->and($throttle->allows(oneCause('Tenant 4 failed.')))->toBeTrue();
});

it('takes the cause from the root exception, so two errors wrapping one cause are one cause', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);
    $root = oneCause('The tenant lookup failed.');

    expect($throttle->allows(IdentityUnavailableException::because('The identity for custom:tenant could not be resolved.', $root)))->toBeTrue()
        ->and($throttle->allows(new LogicException('Another wrapper.', 0, $root)))->toBeFalse();
});

it('tells two causes of one class apart by where they were thrown', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);

    $here = new RuntimeException('Same message.');
    $there = new RuntimeException('Same message.');

    expect([$throttle->allows($here), $throttle->allows($there)])->toBe([true, true]);
});

it('tells two classes thrown on one line apart', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);

    $pair = [new RuntimeException('One.'), new LogicException('One.')];

    expect([$throttle->allows($pair[0]), $throttle->allows($pair[1])])->toBe([true, true]);
});

it('reports every time when the cooldown is 0', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 0, new ProcessFailureMemory);

    expect([$throttle->allows(oneCause('One.')), $throttle->allows(oneCause('Two.'))])->toBe([true, true]);
});

it('reports again when the clock goes back before the last report', function (): void {
    $clock = new FakeClock;
    $throttle = new ReportThrottle($clock, 10, new ProcessFailureMemory);
    $throttle->allows(oneCause('One.'));

    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 - 1);

    expect($throttle->allows(oneCause('Two.')))->toBeTrue();
});

it('AC-10: never reports a cause again that another process reported through the memory they share', function (): void {
    $clock = new FakeClock;
    $shared = new ProcessFailureMemory;
    $first = new ReportThrottle($clock, 10, new ProcessFailureMemory, $shared);
    $second = new ReportThrottle($clock, 10, new ProcessFailureMemory, $shared);

    expect([$first->allows(oneCause('One.')), $second->allows(oneCause('Two.'))])->toBe([true, false]);
});
