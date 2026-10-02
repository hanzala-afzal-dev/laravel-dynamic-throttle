<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\CounterReading;

covers(CounterReading::class);

/*
| SPEC-014: what dynamic-throttle:explain shows of a counter. Limit 5.
*/

it('SPEC-014: what remains is how many more requests the client can make now: the limit less the count, never below 0', function (int $count, int $remaining): void {
    $allowed = $count < 5;
    $next = new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, $allowed, $allowed ? 4 - $count : 0, $allowed ? 0 : 62);

    $reading = new CounterReading($count, $next);

    expect([$reading->count, $reading->next, $reading->remaining()])->toBe([$count, $next, $remaining]);
})->with([
    'nothing counted' => [0, 5],
    'three counted' => [3, 2],
    'at the limit' => [5, 0],
    'over a limit that was lowered' => [7, 0],
]);
