<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Stacking\Scale;
use Random\Engine\Mt19937;
use Random\Randomizer;

covers(Scale::class);

/*
| SPEC-013 AC-6: a rule's limit under the scale factor is
| max(1, ceil(max_attempts × factor)) (ADR-0011 point 5). The factor comes as
| a whole percentage, and the limit is computed in integers, because floating
| point makes 100 × 1.1 into 110.00000000000001, and so its ceiling into 111.
*/

it('AC-6: scales a limit by a whole percentage, rounding up', function (int $maxAttempts, int $percent, int $limit): void {
    expect(Scale::limit($maxAttempts, $percent))->toBe($limit);
})->with([
    'limit 10 by 2' => [10, 200, 20],
    'limit 3 by 0.5' => [3, 50, 2],
    'limit 1 by 0.1, which never goes below 1' => [1, 10, 1],
    'limit 100 by 1.1, which floating point makes 111' => [100, 110, 110],
    'limit 7 by 0.33' => [7, 33, 3],
    'limit 250 by 2.5' => [250, 250, 625],
    'limit 60 by 1' => [60, 100, 60],
    'limit 150 by 10' => [150, 1_000, 1_500],
    'limit 199 by 0.1' => [199, 10, 20],
    'limit 200 by 0.1' => [200, 10, 20],
    'limit 201 by 0.1' => [201, 10, 21],
]);

it('AC-6: gives exactly ceil(limit × percent / 100) on 10,000 seeded limits and factors', function (): void {
    $random = new Randomizer(new Mt19937(20_261_001));
    $wrong = [];

    for ($i = 0; $i < 10_000; $i++) {
        $maxAttempts = $random->getInt(1, 1_000_000);
        $percent = $random->getInt(10, 1_000);
        // Small enough for the product to stay an integer.
        $expected = intdiv($maxAttempts * $percent + 99, 100);

        if (Scale::limit($maxAttempts, $percent) !== $expected) {
            $wrong[] = [$maxAttempts, $percent];
        }
    }

    expect($wrong)->toBe([]);
});

it('AC-6: keeps a limit that scaling would push past PHP\'s largest integer at that integer', function (int $maxAttempts, int $percent, int $limit): void {
    expect(Scale::limit($maxAttempts, $percent))->toBe($limit);
})->with([
    'the largest integer, unscaled' => [PHP_INT_MAX, 100, PHP_INT_MAX],
    'the largest integer by 10' => [PHP_INT_MAX, 1_000, PHP_INT_MAX],
    'the largest integer by 0.5, rounded up' => [PHP_INT_MAX, 50, 4_611_686_018_427_387_904],
    'the largest limit that 10 still fits' => [922_337_203_685_477_580, 1_000, 9_223_372_036_854_775_800],
    'one more, which no longer fits' => [922_337_203_685_477_581, 1_000, PHP_INT_MAX],
]);
