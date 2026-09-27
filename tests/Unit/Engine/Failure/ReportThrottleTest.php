<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ReportThrottle::class, ProcessFailureMemory::class);

/*
| SPEC-012 decision 5: each cause is reported at most once per cooldown. A
| cause is the class of the root exception and where it was thrown: the line
| that made it, and the calls that led there from the package's own code. A
| FakeClock at T0, and a 10-second cooldown unless stated.
*/

/**
 * An exception made on one line, as a factory or a library helper such as abort() makes it.
 */
function oneCause(string $message): RuntimeException
{
    return new RuntimeException($message);
}

/**
 * A library helper that goes through another one, as abort() goes through app()->abort().
 */
function viaHelper(string $message): RuntimeException
{
    return oneCause($message);
}

/**
 * Exceptions thrown the same way, from the same caller, one for each message: one cause.
 *
 * @return list<RuntimeException>
 */
function sameCause(string ...$messages): array
{
    return array_map(fn (string $message): RuntimeException => oneCause($message), array_values($messages));
}

it('reports a cause once per cooldown, whatever its message', function (): void {
    $clock = new FakeClock;
    $throttle = new ReportThrottle($clock, 10, new ProcessFailureMemory);
    [$one, $two, $three, $four] = sameCause('Tenant 1 failed.', 'Tenant 2 failed.', 'Tenant 3 failed.', 'Tenant 4 failed.');

    $first = [$throttle->allows($one), $throttle->allows($two)];
    $clock->setMicroseconds((FakeClock::T0 + 10) * 1_000_000 - 1);
    $justBefore = $throttle->allows($three);
    $clock->setSeconds(FakeClock::T0 + 10);

    expect($first)->toBe([true, false])
        ->and($justBefore)->toBeFalse()
        ->and($throttle->allows($four))->toBeTrue();
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

it('tells apart two causes that one factory made for different callers', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);

    // Both are made on the factory's own line, and thrown from two places.
    $unregistered = IdentityUnavailableException::because('The identity for custom:tenant could not be resolved: no resolver is registered for it.');
    $unusable = IdentityUnavailableException::because('The identity for user could not be resolved: getAuthIdentifier() returned array.');

    expect([$throttle->allows($unregistered), $throttle->allows($unusable)])->toBe([true, true]);
});

it('tells apart two causes that a library helper made for different callers, as abort() does', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);

    $tenantLookup = oneCause('Service Unavailable');
    $planLookup = oneCause('Service Unavailable');

    expect([$throttle->allows($tenantLookup), $throttle->allows($planLookup)])->toBe([true, true]);
});

it('tells apart two callers of a library helper that goes through another one', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);

    $tenantLookup = viaHelper('Service Unavailable');
    $planLookup = viaHelper('Service Unavailable');

    expect([$throttle->allows($tenantLookup), $throttle->allows($planLookup)])->toBe([true, true]);
});

it('stops at the package, so one failure is one cause whatever called the package', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);
    $evaluator = new RuleEvaluator(FailingStore::down());
    $rules = [CompiledRule::fromArray(ManifestFixtures::rule())];
    $client = new readonly class implements IdentityResolver
    {
        public function exemption(CompiledRule $rule): ?string
        {
            return null;
        }

        public function resolve(CompiledRule $rule): string
        {
            return hash('sha256', 'client');
        }
    };

    // The same store failure, reached from two callers, as from two routes' middleware.
    $fromOneCaller = $evaluator->evaluate($rules, $client)->failures[0]->exception;
    $fromAnother = (fn (): Throwable => $evaluator->evaluate($rules, $client)->failures[0]->exception)();

    expect([$throttle->allows($fromOneCaller), $throttle->allows($fromAnother)])->toBe([true, false]);
});

it('reports every time when the cooldown is 0', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 0, new ProcessFailureMemory);
    [$one, $two] = sameCause('One.', 'Two.');

    expect([$throttle->allows($one), $throttle->allows($two)])->toBe([true, true]);
});

it('reports again when the clock goes back before the last report', function (): void {
    $clock = new FakeClock;
    $throttle = new ReportThrottle($clock, 10, new ProcessFailureMemory);
    [$one, $two] = sameCause('One.', 'Two.');
    $throttle->allows($one);

    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 - 1);

    expect($throttle->allows($two))->toBeTrue();
});

it('AC-10: never reports a cause again that another process reported through the memory they share', function (): void {
    $clock = new FakeClock;
    $shared = new ProcessFailureMemory;
    $first = new ReportThrottle($clock, 10, new ProcessFailureMemory, $shared);
    $second = new ReportThrottle($clock, 10, new ProcessFailureMemory, $shared);
    [$one, $two] = sameCause('One.', 'Two.');

    expect([$first->allows($one), $second->allows($two)])->toBe([true, false]);
});

it('remembers nothing when the cooldown is 0, so a throttle sharing its memory still reports the cause', function (): void {
    $clock = new FakeClock;
    $shared = new ProcessFailureMemory;
    $cause = oneCause('One.');

    (new ReportThrottle($clock, 0, $shared))->allows($cause);

    expect((new ReportThrottle($clock, 10, $shared))->allows($cause))->toBeTrue();
});

it('throttles a cause for one second when the cooldown is 1', function (): void {
    $clock = new FakeClock;
    $throttle = new ReportThrottle($clock, 1, new ProcessFailureMemory);
    [$one, $two, $three] = sameCause('One.', 'Two.', 'Three.');

    $within = [$throttle->allows($one), $throttle->allows($two)];
    $clock->setSeconds(FakeClock::T0 + 1);

    expect($within)->toBe([true, false])
        ->and($throttle->allows($three))->toBeTrue();
});

it('tells apart two causes thrown on the same line of different files', function (): void {
    $throttle = new ReportThrottle(new FakeClock, 10, new ProcessFailureMemory);
    [$here, $elsewhere] = sameCause('Same message.', 'Same message.');
    (new ReflectionProperty(Exception::class, 'file'))->setValue($elsewhere, '/app/Other.php');

    expect([$throttle->allows($here), $throttle->allows($elsewhere)])->toBe([true, true]);
});
