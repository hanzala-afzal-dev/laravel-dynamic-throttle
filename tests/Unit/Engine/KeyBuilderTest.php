<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;

covers(KeyBuilder::class);

it('AC-12: builds the window key from the prefix, rule, window, identity hash and window index', function (): void {
    $hash = hash('sha256', 'A');

    expect((new KeyBuilder('dt:'))->counter(new CounterKey('42', $hash), 60, 30_000_000))
        ->toBe("dt:c:42:60:{$hash}:30000000");
});

it('SPEC-003 AC-15: builds the counter base key, and the window key from it', function (): void {
    $keys = new KeyBuilder('dt:');
    $hash = hash('sha256', 'A');
    $key = new CounterKey('42', $hash);

    expect($keys->counterBase($key, 60))->toBe("dt:c:42:60:{$hash}")
        ->and($keys->counter($key, 60, 30_000_000))->toBe($keys->counterBase($key, 60).':30000000');
});

it('AC-12: accepts an empty prefix and the default one', function (): void {
    $key = new CounterKey('1', hash('sha256', 'A'));

    expect((new KeyBuilder(''))->counter($key, 60, 1))->toStartWith('c:1:60:')
        ->and((new KeyBuilder)->counter($key, 60, 1))->toStartWith('dt:c:1:60:');
});

it('AC-12: accepts a 32-character prefix made of allowed characters', function (): void {
    $prefix = 'Az09:_.-'.str_repeat('x', 24);

    expect((new KeyBuilder($prefix))->prefix)->toBe($prefix);
});

it('AC-12: rejects an unsafe prefix', function (string $prefix): void {
    expect(fn (): KeyBuilder => new KeyBuilder($prefix))->toThrow(InvalidArgumentException::class);
})->with([
    '33 characters' => [str_repeat('x', 33)],
    'a space' => ['my app:'],
    'a newline' => ["dt:\n"],
    'a slash' => ['dt/'],
    'a unicode letter' => ['dü:'],
]);
