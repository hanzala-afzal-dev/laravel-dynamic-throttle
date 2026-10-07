<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-015 AC-13 and AC-15 on the Redis driver: the flush sets each row's
| distinct clients, and flushes the minutes of rules that no longer exist.
*/

beforeEach(function (): void {
    Throttling::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

function telemetryOnRedis(string $client): void
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.telemetry', ['host' => (string) env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 0]);
    config()->set('dynamic-throttle.driver', 'redis');
    config()->set('dynamic-throttle.redis_connection', 'telemetry');
    config()->set('dynamic-throttle.prefix', 'dt-flush-'.bin2hex(random_bytes(4)).':');

    expect(Telemetry::buffer())->toBeInstanceOf(RedisTelemetryBuffer::class);
}

/**
 * Deletes one of telemetry's keys, as its expiry would.
 */
function redisTelemetryForget(string $key): void
{
    $prefix = config('dynamic-throttle.prefix');
    app('redis')->connection('telemetry')->command('del', [(is_string($prefix) ? $prefix : '').$key]);
}

function redisFlushAt(string $time): int
{
    return Carbon::parse("2027-01-15 {$time}", 'UTC')->getTimestamp();
}

it('AC-13: sets each row\'s distinct clients to the hour\'s count, again on each flush, never adding', function (string $client): void {
    telemetryOnRedis($client);
    Throttling::rule();
    $buffer = Telemetry::buffer();
    $buffer->record(Telemetry::minute(redisFlushAt('10:56')), 1, [Telemetry::blocked('1', 'A'), Telemetry::counted('1', 'D')]);
    $buffer->record(Telemetry::minute(redisFlushAt('10:56')), 1, [Telemetry::blocked('1', 'B')]);
    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('10:59:30')));
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    $first = Telemetry::rows();

    $buffer->record(Telemetry::minute(redisFlushAt('10:58')), 1, [Telemetry::wouldBlock('1', 'B')]);
    $buffer->record(Telemetry::minute(redisFlushAt('10:58')), 1, [Telemetry::blocked('1', 'C')]);
    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('11:00:30')));
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect($first)->toBe([[1, '2027-01-15 10:00:00', 3, 2, 0, 2]])
        ->and(Telemetry::rows())->toBe([[1, '2027-01-15 10:00:00', 5, 3, 1, 3]]);
})->with(['phpredis', 'predis']);

it('AC-15: flushes the minutes of a rule that no longer exists, under its id', function (string $client): void {
    telemetryOnRedis($client);
    Telemetry::buffer()->record(Telemetry::minute(redisFlushAt('10:58')), 2, [Telemetry::blocked('7', 'A')]);
    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('11:01:30')));

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([[7, '2027-01-15 10:00:00', 2, 2, 0, 1]]);
})->with(['phpredis', 'predis']);

it('AC-13: keeps a row\'s distinct clients when a later flush finds the hour\'s count gone, as when a late minute outlives it', function (string $client): void {
    telemetryOnRedis($client);
    Throttling::rule();
    $buffer = Telemetry::buffer();

    foreach (['A', 'B', 'C'] as $who) {
        $buffer->record(Telemetry::minute(redisFlushAt('10:05')), 1, [Telemetry::blocked('1', $who)]);
    }

    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('10:07:30')));
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    $first = Telemetry::rows();

    $buffer->record(Telemetry::minute(redisFlushAt('10:58')), 1, [Telemetry::counted('1', 'D')]);
    // The hour's count expires 2 hours after its first block, so before the 10:58 minute does.
    redisTelemetryForget('t:u:1:'.Telemetry::hour(redisFlushAt('10:05')));
    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('12:10:30')));
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect($first)->toBe([[1, '2027-01-15 10:00:00', 3, 3, 0, 3]])
        ->and(Telemetry::rows())->toBe([[1, '2027-01-15 10:00:00', 4, 3, 0, 3]]);
})->with(['phpredis', 'predis']);

it('AC-13: leaves a row\'s clients unknown when the hour\'s count is gone at its first flush, and sets 0 where nothing was blocked', function (string $client): void {
    telemetryOnRedis($client);
    Telemetry::buffer()->record(Telemetry::minute(redisFlushAt('10:58')), 1, [Telemetry::blocked('1', 'A'), Telemetry::counted('2', 'B')]);
    redisTelemetryForget('t:u:1:'.Telemetry::hour(redisFlushAt('10:58')));
    $this->travelTo(Carbon::createFromTimestamp(redisFlushAt('11:00:30')));

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 1, 1, 0, null],
        [2, '2027-01-15 10:00:00', 1, 0, 0, 0],
    ]);
})->with(['phpredis', 'predis']);
