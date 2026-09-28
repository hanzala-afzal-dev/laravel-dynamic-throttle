<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Support\ApcuFailureMemory;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;

covers(ApcuFailureMemory::class);

/*
| SPEC-012 AC-10 and AC-11 with APCu itself. `composer test:apcu` runs this
| group with apc.enable_cli=1. Everywhere else APCu stays off, so no state
| leaks between tests, and these skip.
*/

beforeEach(function (): void {
    if (! ApcuFailureMemory::available()) {
        $this->markTestSkipped('APCu is off. Run composer test:apcu.');
    }

    apcu_clear_cache();
});

it('AC-10: keeps the cooldown where another process sees it', function (): void {
    $keys = new KeyBuilder('dt:');

    (new ApcuFailureMemory($keys, 'app'))->startCooldown(FakeClock::T0 * 1_000_000, 10);

    expect((new ApcuFailureMemory($keys, 'app'))->cooldownStartedAt())->toBe(FakeClock::T0 * 1_000_000);
});

it('AC-10: keeps each reported cause where another process sees it', function (): void {
    $keys = new KeyBuilder('dt:');

    (new ApcuFailureMemory($keys, 'app'))->markReported('cause-a', FakeClock::T0 * 1_000_000, 10);
    $other = new ApcuFailureMemory($keys, 'app');

    expect([$other->reportedAt('cause-a'), $other->reportedAt('cause-b')])->toBe([FakeClock::T0 * 1_000_000, null]);
});

it('remembers nothing until something fails', function (): void {
    expect((new ApcuFailureMemory(new KeyBuilder('dt:'), 'app'))->cooldownStartedAt())->toBeNull();
});

it('AC-11: keys its entries with KeyBuilder, so apps with different prefixes never share them', function (): void {
    (new ApcuFailureMemory(new KeyBuilder('one:'), 'app'))->startCooldown(FakeClock::T0 * 1_000_000, 10);

    expect(apcu_exists((new KeyBuilder('one:'))->cooldown('app')))->toBeTrue()
        ->and((new ApcuFailureMemory(new KeyBuilder('two:'), 'app'))->cooldownStartedAt())->toBeNull();
});

it('AC-11: never shares its entries with another app of the server, even under the same prefix', function (): void {
    $keys = new KeyBuilder('dt:');
    (new ApcuFailureMemory($keys, 'staging'))->startCooldown(FakeClock::T0 * 1_000_000, 10);
    (new ApcuFailureMemory($keys, 'staging'))->markReported('cause-a', FakeClock::T0 * 1_000_000, 10);
    $production = new ApcuFailureMemory($keys, 'production');

    expect([$production->cooldownStartedAt(), $production->reportedAt('cause-a')])->toBe([null, null]);
});
