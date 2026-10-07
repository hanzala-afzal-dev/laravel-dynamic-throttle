<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\CacheTelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Illuminate\Cache\RedisStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Redis\RedisManager;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

covers(CacheTelemetryBuffer::class, RedisTelemetryBuffer::class, MinuteCounts::class);

/*
|--------------------------------------------------------------------------
| SPEC-015 and SPEC-016: the shared suite every TelemetryBuffer passes
|--------------------------------------------------------------------------
|
| Local stores always run; service stores run with DYNAMIC_THROTTLE_DRIVERS=1,
| as the LimiterStore contract suite does. Each buffer gets a prefix of its
| own, so runs against shared services never see each other's numbers. What
| only one driver does (offenders, distinct clients, expiry, round trips) is
| in its own tests.
|
*/

function telemetryKeys(): KeyBuilder
{
    return new KeyBuilder('dt-tel-'.bin2hex(random_bytes(4)).':');
}

function telemetryRedisManager(string $client, string $host, int $port): RedisManager
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    return new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0],
    ]);
}

function telemetryCacheTable(string $connection): void
{
    if (Schema::connection($connection)->hasTable('cache')) {
        return;
    }

    Schema::connection($connection)->create('cache', function (Blueprint $table): void {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration')->index();
    });
}

function telemetryCacheBuffer(CacheRepository $cache): CacheTelemetryBuffer
{
    return new CacheTelemetryBuffer($cache, telemetryKeys());
}

dataset('telemetry buffers', function (): iterable {
    yield 'cache buffer, array store' => [fn (): TelemetryBuffer => telemetryCacheBuffer(Cache::store('array'))];

    yield 'cache buffer, file store' => [fn (): TelemetryBuffer => telemetryCacheBuffer(Cache::build([
        'driver' => 'file',
        'path' => sys_get_temp_dir().'/dynamic-throttle-telemetry-'.bin2hex(random_bytes(4)),
    ]))];

    yield 'cache buffer, database store (SQLite)' => [function (): TelemetryBuffer {
        telemetryCacheTable('testing');

        return telemetryCacheBuffer(Cache::build(['driver' => 'database', 'connection' => 'testing', 'table' => 'cache']));
    }];

    if (getenv('DYNAMIC_THROTTLE_DRIVERS') !== '1') {
        return;
    }

    yield 'cache buffer, Memcached' => [function (): TelemetryBuffer {
        if (! extension_loaded('memcached')) {
            test()->markTestSkipped('ext-memcached is not installed.');
        }

        return telemetryCacheBuffer(Cache::build([
            'driver' => 'memcached',
            'servers' => [['host' => (string) env('MEMCACHED_HOST', '127.0.0.1'), 'port' => (int) env('MEMCACHED_PORT', 11211), 'weight' => 100]],
        ]));
    }];

    foreach (['phpredis', 'predis'] as $client) {
        foreach (['Redis' => [(string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)], 'Valkey' => [(string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)]] as $service => [$host, $port]) {
            yield "cache buffer, {$service} store ({$client})" => [fn (): TelemetryBuffer => telemetryCacheBuffer(
                new Repository(new RedisStore(telemetryRedisManager($client, $host, $port))),
            )];
            yield "Redis buffer, {$service} ({$client})" => [fn (): TelemetryBuffer => new RedisTelemetryBuffer(
                telemetryRedisManager($client, $host, $port),
                'default',
                telemetryKeys(),
            )];
        }
    }
});

it('SPEC-015 AC-1: adds each record to its rule\'s numbers for the minute, weighted', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 1, [Telemetry::counted('1'), Telemetry::wouldBlock('2')]);
    $buffer->record($minute, 1, [Telemetry::blocked('1')]);
    // A sampled request that stands for 3.
    $buffer->record($minute, 3, [Telemetry::counted('1'), Telemetry::wouldBlock('2', 'B')]);

    expect(Telemetry::counts($minute, ['1', '2', '3'], $buffer))->toEqual([
        '1' => [5, 1, 0],
        '2' => [4, 0, 4],
    ]);
})->with('telemetry buffers');

it('SPEC-015 AC-1: adds up two records of one rule in one request', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 2, [Telemetry::counted('1'), Telemetry::blocked('1', 'B')]);

    expect(Telemetry::counts($minute, ['1'], $buffer))->toEqual(['1' => [4, 2, 0]]);
})->with('telemetry buffers');

it('SPEC-015 AC-1: keeps each minute apart', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 1, [Telemetry::counted('1')]);
    $buffer->record($minute + 1, 1, [Telemetry::blocked('1')]);
    $buffer->record($minute + 1, 1, [Telemetry::blocked('1')]);

    expect([Telemetry::counts($minute, ['1'], $buffer), Telemetry::counts($minute + 1, ['1'], $buffer), Telemetry::counts($minute + 2, ['1'], $buffer)])
        ->toEqual([['1' => [1, 0, 0]], ['1' => [2, 2, 0]], []]);
})->with('telemetry buffers');

it('SPEC-015: records nothing for a request without records', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 1, []);

    expect(Telemetry::counts($minute, ['1'], $buffer))->toBe([]);
})->with('telemetry buffers');

it('SPEC-015: rejects a weight below 1, before writing anything', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    expect(fn () => $buffer->record($minute, 0, [Telemetry::counted('1')]))->toThrow(InvalidArgumentException::class)
        ->and(Telemetry::counts($minute, ['1'], $buffer))->toBe([]);
})->with('telemetry buffers');

it('SPEC-015 AC-14: remembers the last minute flushed, and knows none at first', function (TelemetryBuffer $buffer): void {
    $first = $buffer->flushedThrough();
    $buffer->markFlushed(30_000_000);
    $marked = $buffer->flushedThrough();
    $buffer->markFlushed(30_000_005);

    expect([$first, $marked, $buffer->flushedThrough()])->toBe([null, 30_000_000, 30_000_005]);
})->with('telemetry buffers');

it('SPEC-016 AC-14: adds a guest\'s request to its rule\'s guests as well as to its hits, weighted', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 1, [Telemetry::guest('1'), Telemetry::counted('2')]);
    $buffer->record($minute, 3, [Telemetry::guest('1', 'B', TelemetryOutcome::Blocked), Telemetry::guest('2', 'C', TelemetryOutcome::WouldBlock)]);

    expect(Telemetry::all($minute, ['1', '2'], $buffer))->toEqual([
        '1' => [4, 3, 0, 4, 0, 0],
        '2' => [4, 0, 3, 3, 0, 0],
    ]);
})->with('telemetry buffers');

it('SPEC-016 AC-14: counts the requests a rule skipped or was ignored for apart, as no hit, weighted', function (TelemetryBuffer $buffer): void {
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 1, [Telemetry::counted('1'), Telemetry::skipped('2'), Telemetry::ignored('3')]);
    $buffer->record($minute, 2, [Telemetry::skipped('2'), Telemetry::ignored('3')]);

    expect(Telemetry::all($minute, ['1', '2', '3'], $buffer))->toEqual([
        '1' => [1, 0, 0, 0, 0, 0],
        '2' => [0, 0, 0, 0, 3, 0],
        '3' => [0, 0, 0, 0, 0, 3],
    ]);
})->with('telemetry buffers');

it('SPEC-016 AC-15: records the same numbers with a client, and the hour\'s traffic when the driver keeps it, but nothing for a request without records', function (TelemetryBuffer $buffer): void {
    $time = 1_800_000_000;
    $client = hash('sha256', 'ip:203.0.113.7');

    $buffer->record(Telemetry::minute($time), 2, [Telemetry::counted('1')], $client);
    $buffer->record(Telemetry::minute($time), 1, [], $client);

    // A driver that keeps no traffic gives null for both.
    expect(Telemetry::counts(Telemetry::minute($time), ['1'], $buffer))->toEqual(['1' => [2, 0, 0]])
        ->and([$buffer->requests(Telemetry::hour($time)), $buffer->topClients(Telemetry::hour($time))])->toBeIn([[null, null], [2, [$client => 2]]]);
})->with('telemetry buffers');
