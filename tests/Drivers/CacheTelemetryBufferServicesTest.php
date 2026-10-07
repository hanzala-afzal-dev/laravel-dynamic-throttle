<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\CacheTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Redis\RedisManager;

covers(CacheTelemetryBuffer::class);

/*
| SPEC-015: the cache driver's buffer on what only a real service shows, as
| CacheLimiterStoreServicesTest does for the counters (SPEC-002 AC-19, AC-20).
*/

it('AC-1: records and reads back on a Redis cache store whose phpredis connection serializes values', function (): void {
    if (! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    $redis = new RedisManager(new Container, 'phpredis', [
        'default' => [
            'host' => (string) env('REDIS_HOST', '127.0.0.1'),
            'port' => (int) env('REDIS_PORT', 6379),
            'database' => 0,
            'serializer' => Redis::SERIALIZER_PHP,
        ],
    ]);
    $buffer = new CacheTelemetryBuffer(new Repository(new RedisStore($redis)), new KeyBuilder('dt-tel-serializer-'.bin2hex(random_bytes(4)).':'));
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 2, [Telemetry::blocked('1')]);
    $buffer->record($minute, 1, [Telemetry::counted('1')]);
    $buffer->markFlushed($minute);

    expect([Telemetry::counts($minute, ['1'], $buffer), $buffer->flushedThrough()])->toBe([['1' => [3, 2, 0]], $minute]);
});

it('AC-8: reports a minute or a last minute flushed that Memcached failed to read as unavailable, not as nothing', function (): void {
    if (! extension_loaded('memcached')) {
        $this->markTestSkipped('ext-memcached is not installed.');
    }

    // Reads go to a server that is down, so they give null with an error result code.
    $down = new Memcached;
    $down->addServer('127.0.0.1', 1);
    $buffer = new CacheTelemetryBuffer(new Repository(new MemcachedStore($down)), new KeyBuilder('dt-tel-memcached-'.bin2hex(random_bytes(4)).':'));

    // The messages name Memcached's own error, such as "SERVER IS MARKED DEAD".
    expect(fn (): array => $buffer->counts(Telemetry::minute(1_800_000_000), ['1']))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not read the minute: \S/');
        })
        ->and(fn (): ?int => $buffer->flushedThrough())
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getMessage())->toMatch('/\AMemcached could not read the last minute flushed: \S/');
        });
});
