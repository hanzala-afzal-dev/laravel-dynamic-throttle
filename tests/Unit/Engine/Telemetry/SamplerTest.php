<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Telemetry\Sampler;
use Random\Engine;
use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

covers(Sampler::class);

/*
| SPEC-015 AC-7 and decision 6: sampling picks whole requests, and weighs each
| one so the counts stay unbiased. The sampler draws one float per request to
| pick it, picked below the rate, and one more to round the weight up, only
| when 1/rate is not whole.
*/

/**
 * @return list<int> the weights of $requests requests
 */
function sampledWeights(Sampler $sampler, int $requests): array
{
    return array_map(fn (): int => $sampler->weight(), range(1, $requests));
}

it('AC-7: records every request, with weight 1, at the rate 1', function (): void {
    expect(array_unique(sampledWeights(new Sampler(1.0, new Randomizer(new Xoshiro256StarStar(7))), 1_000)))->toBe([1]);
});

it('AC-7: records no request at the rate 0', function (): void {
    expect(array_unique(sampledWeights(new Sampler(0.0, new Randomizer(new Xoshiro256StarStar(7))), 1_000)))->toBe([0]);
});

it('AC-7: records exactly the requests the random source picks at the rate 0.25, each with weight 4', function (): void {
    $sampler = new Sampler(0.25, new Randomizer(new Xoshiro256StarStar(42)));
    $twin = new Randomizer(new Xoshiro256StarStar(42));

    // 1/0.25 is whole, so only the pick draws.
    $expected = array_map(fn (): int => $twin->nextFloat() < 0.25 ? 4 : 0, range(1, 1_000));

    expect(sampledWeights($sampler, 1_000))->toBe($expected)
        ->and(array_count_values($expected)[4])->toBeGreaterThan(200)->toBeLessThan(300);
});

it('AC-7: weighs each request picked at the rate 0.3 as 3 or 4, as the second draw says, so the total stays unbiased', function (): void {
    $sampler = new Sampler(0.3, new Randomizer(new Xoshiro256StarStar(42)));
    $twin = new Randomizer(new Xoshiro256StarStar(42));
    // 1/0.3 = 3.333…: 4 with probability 0.333…, else 3.
    $fraction = 1 / 0.3 - 3;

    $expected = array_map(function () use ($twin, $fraction): int {
        if ($twin->nextFloat() >= 0.3) {
            return 0;
        }

        return $twin->nextFloat() < $fraction ? 4 : 3;
    }, range(1, 1_000));

    expect(sampledWeights($sampler, 1_000))->toBe($expected);
});

it('AC-7: keeps the total within 2% of the requests made at the rate 0.3, over 100,000 requests', function (): void {
    $total = array_sum(sampledWeights(new Sampler(0.3, new Randomizer(new Xoshiro256StarStar(2027))), 100_000));

    expect(abs($total - 100_000))->toBeLessThan(2_000);
});

it('AC-7: rejects a rate outside 0 to 1, and names it', function (float $rate, string $message): void {
    expect(fn (): Sampler => new Sampler($rate))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'below 0' => [-0.1, 'The sample rate must be from 0 to 1, got -0.1.'],
    'above 1' => [1.5, 'The sample rate must be from 0 to 1, got 1.5.'],
    // Named, never converted: PHP 8.5 warns when NaN is converted to a string.
    'NaN' => [NAN, 'The sample rate must be from 0 to 1, got NaN.'],
]);

/**
 * A random source that gives these draws, in order, and fails when it runs out.
 *
 * @param  list<float>  $draws  each 0, or a multiple of 2^-53 below 1
 */
function scriptedDraws(array $draws): Randomizer
{
    return new Randomizer(new class($draws) implements Engine
    {
        /** @param  list<float>  $draws */
        public function __construct(private array $draws) {}

        public function generate(): string
        {
            $draw = array_shift($this->draws) ?? throw new RuntimeException('The sampler drew more than the test expects.');

            // nextFloat() is the top 53 bits of 64, times 2^-53.
            return pack('P', (int) ($draw * 2 ** 53) << 11);
        }
    });
}

it('AC-7: draws nothing at the rate 1, the default, so no request pays for a draw', function (): void {
    expect((new Sampler(1.0, scriptedDraws([])))->weight())->toBe(1);
});

it('AC-7: never picks a request at the rate 0, not even for a draw of exactly 0', function (): void {
    expect((new Sampler(0.0, scriptedDraws([0.0])))->weight())->toBe(0);
});

it('AC-7: rounds 1/rate down, and up only for a second draw below the fractional part', function (float $second, int $weight): void {
    // 1/0.4 = 2.5: a picked request weighs 2, or 3 with probability 0.5.
    expect((new Sampler(0.4, scriptedDraws([0.0, $second])))->weight())->toBe($weight);
})->with([
    'a draw below the fraction' => [0.25, 3],
    'a draw equal to it' => [0.5, 2],
    'a draw above it' => [0.75, 2],
]);

it('AC-7: weighs a request picked at a tiny rate as 2^53 at most, which an integer holds exactly', function (): void {
    expect((new Sampler(1e-300, scriptedDraws([0.0])))->weight())->toBe(9_007_199_254_740_992);
});
