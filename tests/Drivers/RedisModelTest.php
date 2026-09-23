<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;
use Random\Engine\Mt19937;
use Random\Randomizer;

covers(RedisLimiterStore::class);

/*
| SPEC-003 AC-8 and ADR-0016: random traffic with a fake clock. The Redis
| driver (Lua) must decide exactly like the PHP reference, the cache driver on
| an array store, and no aligned window may allow more than its max.
*/

/**
 * Bursty traffic: usually several attempts on one counter in quick
 * succession, so rejections and window crossings happen often.
 *
 * @return iterable<int, array{int, string, int, int}> microseconds, identity, window, max
 */
function redisModelTraffic(int $seed, int $steps): iterable
{
    $random = new Randomizer(new Mt19937($seed));
    $now = FakeClock::T0 * 1_000_000;
    [$identity, $window, $max] = ['A', 60, 5];

    for ($step = 1; $step <= $steps; $step++) {
        if ($step === 1 || $random->getInt(1, 10) <= 3) {
            $identity = ['A', 'B', 'C'][$random->getInt(0, 2)];
            $window = [3, 7, 60][$random->getInt(0, 2)];
            $max = $random->getInt(1, 10);
        }

        $gap = $random->getInt(1, 10);
        $now += match (true) {
            $gap <= 6 => $random->getInt(0, 100_000),
            $gap <= 9 => $random->getInt(0, 3_000_000),
            default => $random->getInt(0, 130_000_000),
        };

        yield $step => [$now, $identity, $window, $max];
    }
}

/**
 * @return array{bool, int, int, int}
 */
function redisModelSummary(Attempt $attempt): array
{
    return [$attempt->allowed, $attempt->remaining, $attempt->retryAfter, $attempt->windowIndex];
}

it('AC-8: decides exactly like the PHP reference on random traffic', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $clock = new FakeClock;
    $prefix = 'dt-model-'.bin2hex(random_bytes(4)).':';
    $redis = new RedisLimiterStore(new RedisManager(new Container, $client, [
        'default' => ['host' => (string) env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 0],
    ]), 'default', new KeyBuilder($prefix), $clock);
    $reference = new CacheLimiterStore(new Repository(new ArrayStore), new KeyBuilder($prefix), $clock);

    $mismatches = [];
    $overLimit = [];
    $allowedPerWindow = [];
    $decisions = ['allowed' => 0, 'rejected' => 0];
    $lastWindow = [];
    $nextWindowAttempts = 0;

    foreach (redisModelTraffic(20_260_923, 2_000) as $step => [$now, $identity, $window, $max]) {
        $clock->setMicroseconds($now);
        $key = new CounterKey('1', hash('sha256', $identity));

        $actual = redisModelSummary($redis->attempt($key, $max, $window));
        $expected = redisModelSummary($reference->attempt($key, $max, $window));

        if ($actual !== $expected) {
            $mismatches[] = sprintf('step %d (%s, window %d, max %d): Redis %s, reference %s',
                $step, $identity, $window, $max, json_encode($actual), json_encode($expected));
        }

        $decisions[$actual[0] ? 'allowed' : 'rejected']++;

        // Attempts in the window right after the counter's previous attempt use the previous-window weight.
        $counter = "{$identity}:{$window}";
        $nextWindowAttempts += ($lastWindow[$counter] ?? null) === $actual[3] - 1 ? 1 : 0;
        $lastWindow[$counter] = $actual[3];

        if ($actual[0]) {
            $bucket = "{$identity}:{$window}:{$actual[3]}";
            $allowedPerWindow[$bucket] = ($allowedPerWindow[$bucket] ?? 0) + 1;

            if ($allowedPerWindow[$bucket] > $max) {
                $overLimit[] = "step {$step}: window {$bucket} allowed {$allowedPerWindow[$bucket]} with max {$max}";
            }
        }
    }

    expect($mismatches)->toHaveCount(0, implode("\n", array_slice($mismatches, 0, 5)))
        ->and($overLimit)->toHaveCount(0, implode("\n", array_slice($overLimit, 0, 5)))
        // The traffic must exercise every path, or matching decisions would prove little.
        // With this seed the reference allows 1,530, rejects 470 and sees 165 window changes.
        ->and($decisions['allowed'])->toBeGreaterThan(1_000)
        ->and($decisions['rejected'])->toBeGreaterThan(300)
        ->and($nextWindowAttempts)->toBeGreaterThan(100);
})->with(['phpredis', 'predis']);
