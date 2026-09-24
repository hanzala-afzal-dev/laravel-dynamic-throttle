<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;

covers(AttemptGuard::class);

/*
| SPEC-002 AC-16: the checks every driver runs before touching its store. The
| contract suite proves each driver runs them; these tests pin the limits and
| the messages, because `covers()` keeps the contract suite from counting as
| coverage of this class.
*/

it('SPEC-002 AC-16: accepts the smallest and largest valid values', function (int $max, int $window): void {
    expect(fn () => AttemptGuard::check($max, $window))->not->toThrow(InvalidArgumentException::class);
})->with([
    'max 1, window 1' => [1, 1],
    'window 86,400 (ADR-0015)' => [5, 86_400],
]);

it('SPEC-002 AC-16: names the invalid value', function (int $max, int $window, string $message): void {
    expect(fn () => AttemptGuard::check($max, $window))
        ->toThrow(InvalidArgumentException::class, $message);
})->with([
    'max 0' => [0, 60, 'The limit must be at least 1, got 0.'],
    'max -1' => [-1, 60, 'The limit must be at least 1, got -1.'],
    'window 0' => [5, 0, 'The window must be from 1 to 86400 seconds, got 0.'],
    'window 86,401' => [5, 86_401, 'The window must be from 1 to 86400 seconds, got 86401.'],
]);
