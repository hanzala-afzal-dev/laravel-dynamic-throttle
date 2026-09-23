<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Algorithm\SlidingWindowCounter;

covers(SlidingWindowCounter::class);

/*
|--------------------------------------------------------------------------
| SPEC-001: sliding-window counter formula
|--------------------------------------------------------------------------
|
| docs/specs/001-sliding-window-counter.md. Rows use window = 60 and
| max = 10 unless they say otherwise.
|
*/

it('allows the request and reports what remains', function (int $previous, int $current, float $elapsed, int $window, int $max, int $remaining, float $estimate): void {
    $evaluation = (new SlidingWindowCounter)->evaluate($previous, $current, $elapsed, $window, $max);

    expect($evaluation->allowed)->toBeTrue()
        ->and($evaluation->remaining)->toBe($remaining)
        ->and($evaluation->retryAfter)->toBe(0)
        ->and($evaluation->estimate)->toBe($estimate);
})->with([
    'AC-1: previous 0, current 9, elapsed 30 → remaining 0' => [0, 9, 30.0, 60, 10, 0, 9.0],
    'AC-3: previous 10, current 0, elapsed 30 → remaining 4' => [10, 0, 30.0, 60, 10, 4, 5.0],
    'AC-4: previous 10, current 4, elapsed 30 → remaining 0' => [10, 4, 30.0, 60, 10, 0, 9.0],
    'AC-8: previous 5, current 0, elapsed 30 → remaining 6' => [5, 0, 30.0, 60, 10, 6, 2.5],
    'AC-10: window 3600, max 100, previous 100, current 20, elapsed 900 → remaining 4' => [100, 20, 900.0, 3600, 100, 4, 95.0],
]);

it('rejects the request and says how long to wait', function (int $previous, int $current, float $elapsed, int $window, int $max, int $retryAfter, float $estimate): void {
    $evaluation = (new SlidingWindowCounter)->evaluate($previous, $current, $elapsed, $window, $max);

    expect($evaluation->allowed)->toBeFalse()
        ->and($evaluation->remaining)->toBe(0)
        ->and($evaluation->retryAfter)->toBe($retryAfter)
        ->and($evaluation->estimate)->toBe($estimate);
})->with([
    'AC-2: previous 0, current 10, elapsed 30 → rejected' => [0, 10, 30.0, 60, 10, 36, 10.0],
    'AC-5: previous 10, current 0, elapsed 0 → retryAfter 6' => [10, 0, 0.0, 60, 10, 6, 10.0],
    'AC-6: previous 10, current 5, elapsed 30 → retryAfter 6' => [10, 5, 30.0, 60, 10, 6, 10.0],
    'AC-7: previous 0, current 10, elapsed 30 → retryAfter 36' => [0, 10, 30.0, 60, 10, 36, 10.0],
    'AC-9: previous 7, current 6, elapsed 30 → retryAfter 5 (exactly 4.29 s)' => [7, 6, 30.0, 60, 10, 5, 9.5],
]);

/**
 * Every combination of small inputs, with elapsed in half-second steps.
 * Halves are exact in binary floating point, so boundaries are exact too.
 *
 * @return iterable<array{int, int, float, int, int}>
 */
function slidingWindowGrid(): iterable
{
    foreach ([1, 3, 7, 60] as $window) {
        foreach ([1, 2, 5, 10] as $max) {
            foreach (range(0, 11) as $previous) {
                foreach (range(0, 11) as $current) {
                    for ($elapsed = 0.0; $elapsed < $window; $elapsed += 0.5) {
                        yield [$previous, $current, $elapsed, $window, $max];
                    }
                }
            }
        }
    }
}

/**
 * Reference model: is a request allowed after waiting $wait seconds with no
 * new traffic? It only uses the estimate formula, so it checks retryAfter
 * without reusing the implementation's retry math.
 */
function allowedAfterWaiting(int $previous, int $current, float $elapsed, int $window, int $max, int $wait): bool
{
    $at = $elapsed + $wait;

    $estimate = match (true) {
        $at < $window => $previous * ($window - $at) / $window + $current,
        // Next window: this window's count becomes the previous one.
        $at < 2 * $window => $current * (2 * $window - $at) / $window,
        default => 0.0,
    };

    return $estimate + 1 <= $max;
}

it('AC-11: retryAfter is at least 1 when rejected and 0 when allowed', function (): void {
    $counter = new SlidingWindowCounter;
    $violations = [];

    foreach (slidingWindowGrid() as [$previous, $current, $elapsed, $window, $max]) {
        $evaluation = $counter->evaluate($previous, $current, $elapsed, $window, $max);
        $valid = $evaluation->allowed ? $evaluation->retryAfter === 0 : $evaluation->retryAfter >= 1;

        if (! $valid) {
            $violations[] = "previous {$previous}, current {$current}, elapsed {$elapsed}, window {$window}, max {$max}";
        }
    }

    expect($violations)->toHaveCount(0, implode("\n", array_slice($violations, 0, 5)));
});

it('decides exactly as the formula says for every input on the grid', function (): void {
    $counter = new SlidingWindowCounter;
    $violations = [];

    foreach (slidingWindowGrid() as [$previous, $current, $elapsed, $window, $max]) {
        $evaluation = $counter->evaluate($previous, $current, $elapsed, $window, $max);
        $estimate = $previous * ($window - $elapsed) / $window + $current;
        $remaining = $evaluation->allowed ? $max - (int) ceil($estimate + 1) : 0;

        if ($evaluation->allowed !== allowedAfterWaiting($previous, $current, $elapsed, $window, $max, 0)
            || $evaluation->remaining !== $remaining
            || $evaluation->estimate !== $estimate) {
            $violations[] = "previous {$previous}, current {$current}, elapsed {$elapsed}, window {$window}, max {$max}";
        }
    }

    expect($violations)->toHaveCount(0, implode("\n", array_slice($violations, 0, 5)));
});

it('retryAfter is the smallest whole second after which a request is allowed', function (): void {
    $counter = new SlidingWindowCounter;
    $violations = [];

    foreach (slidingWindowGrid() as [$previous, $current, $elapsed, $window, $max]) {
        $evaluation = $counter->evaluate($previous, $current, $elapsed, $window, $max);

        if ($evaluation->allowed) {
            continue;
        }

        $wait = $evaluation->retryAfter;
        $input = "previous {$previous}, current {$current}, elapsed {$elapsed}, window {$window}, max {$max}";

        if (! allowedAfterWaiting($previous, $current, $elapsed, $window, $max, $wait)) {
            $violations[] = "{$input}: still rejected after {$wait} s";
        } elseif ($wait > 1 && allowedAfterWaiting($previous, $current, $elapsed, $window, $max, $wait - 1)) {
            $violations[] = "{$input}: already allowed after ".($wait - 1).' s';
        }
    }

    expect($violations)->toHaveCount(0, implode("\n", array_slice($violations, 0, 5)));
});

it('AC-12: rejects invalid input', function (int $previous, int $current, float $elapsed, int $window, int $max): void {
    (new SlidingWindowCounter)->evaluate($previous, $current, $elapsed, $window, $max);
})->with([
    'max < 1' => [0, 0, 0.0, 60, 0],
    'window < 1' => [0, 0, 0.0, 0, 10],
    'elapsed < 0' => [0, 0, -0.5, 60, 10],
    'elapsed = window' => [0, 0, 60.0, 60, 10],
    'elapsed > window' => [0, 0, 60.5, 60, 10],
    'elapsed is NaN' => [0, 0, NAN, 60, 10],
    'previous < 0' => [-1, 0, 0.0, 60, 10],
    'current < 0' => [0, -1, 0.0, 60, 10],
])->throws(InvalidArgumentException::class);
