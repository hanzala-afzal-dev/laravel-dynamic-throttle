<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Symfony\Component\Process\Process;

/*
| SPEC-003 AC-9 and ADR-0016: the Lua script decides atomically, so under
| concurrency the Redis driver allows exactly max, never more and never less.
*/

it('AC-9: allows exactly the limit with 50 concurrent processes', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $host = (string) env('REDIS_HOST', '127.0.0.1');
    $port = (int) env('REDIS_PORT', 6379);
    $prefix = 'dt-concurrency-'.bin2hex(random_bytes(4)).':';
    $startAt = microtime(true) + 3.0;
    $processes = [];

    foreach (range(1, 50) as $ignored) {
        $process = new Process([
            PHP_BINARY, __DIR__.'/../Support/concurrency-worker.php',
            'redis-driver', $host, (string) $port, $prefix,
            '20', '100', '60', (string) (FakeClock::T0 + 10), (string) $startAt,
        ]);
        $process->start();
        $processes[] = $process;
    }

    $allowed = 0;

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        $allowed += (int) $process->getOutput();
    }

    $redis = new Redis;
    $redis->connect($host, $port, 1.0);
    $hashKey = (new KeyBuilder($prefix))->counterBase(new CounterKey('1', hash('sha256', 'concurrency')), 60);

    expect($allowed)->toBe(100)
        ->and((int) $redis->hGet($hashKey, '30000000'))->toBe(100);
})->group('concurrency');

it('SPEC-004 AC-13: keeps exact counts when half the processes release their attempts', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $host = (string) env('REDIS_HOST', '127.0.0.1');
    $port = (int) env('REDIS_PORT', 6379);
    $prefix = 'dt-concurrency-'.bin2hex(random_bytes(4)).':';
    $startAt = microtime(true) + 3.0;
    $processes = [];

    // The 25 odd-numbered processes release every attempt they were allowed.
    foreach (range(1, 50) as $number) {
        $process = new Process([
            PHP_BINARY, __DIR__.'/../Support/concurrency-worker.php',
            'redis-driver', $host, (string) $port, $prefix,
            '20', '100', '60', (string) (FakeClock::T0 + 10), (string) $startAt, $number % 2 === 1 ? 'release' : 'keep',
        ]);
        $process->start();
        $processes[] = $process;
    }

    $kept = 0;

    foreach ($processes as $process) {
        $process->wait();
        expect($process->isSuccessful())->toBeTrue($process->getErrorOutput());
        [$allowed, $released] = array_pad(array_map(intval(...), explode(' ', trim($process->getOutput()))), 2, 0);
        $kept += $allowed - $released;
    }

    $redis = new Redis;
    $redis->connect($host, $port, 1.0);
    $hashKey = (new KeyBuilder($prefix))->counterBase(new CounterKey('1', hash('sha256', 'concurrency')), 60);

    // At most 25 releasing processes hold a count at once, so at least 75 are kept.
    expect($kept)->toBeLessThanOrEqual(100)
        ->and($kept)->toBeGreaterThanOrEqual(75)
        ->and((int) $redis->hGet($hashKey, '30000000'))->toBe($kept);
})->group('concurrency');
