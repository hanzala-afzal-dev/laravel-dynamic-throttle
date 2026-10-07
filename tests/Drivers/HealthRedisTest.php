<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-016 on the Redis driver: the hour's traffic that requests record (AC-15),
| the dominant-IP check that reads it (AC-17), and a health check that changes
| no key (AC-3). Redis and Valkey, with phpredis and predis. The clock stands
| at 2027-01-15 11:00:30 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

dataset('health clients and services', function (): iterable {
    foreach (['phpredis', 'predis'] as $client) {
        yield "{$client}, Redis" => [$client, (string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)];
        yield "{$client}, Valkey" => [$client, (string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)];
    }
});

/**
 * The Redis driver on a connection of its own, under a prefix of its own. The
 * manifest stays in the default cache store, the array store.
 */
function healthOnRedis(string $client, string $host, int $port): void
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.health', ['host' => $host, 'port' => $port, 'database' => 0]);
    config()->set('dynamic-throttle.driver', 'redis');
    config()->set('dynamic-throttle.redis_connection', 'health');
    config()->set('dynamic-throttle.prefix', 'dt-health-'.bin2hex(random_bytes(4)).':');

    expect(Telemetry::buffer())->toBeInstanceOf(RedisTelemetryBuffer::class);
}

/**
 * Records one request at a time of 2027-01-15, such as "10:30", from a client IP, that stands for $weight.
 */
function healthTraffic(string $time, int $weight, string $ip): void
{
    Telemetry::buffer()->record(Telemetry::minute(Carbon::parse("2027-01-15 {$time}", 'UTC')->getTimestamp()), $weight, [Telemetry::counted('1')], Telemetry::ipClient($ip));
}

/**
 * Every key under the test's prefix, with its value as DUMP gives it.
 *
 * @return array<string, mixed>
 */
function healthRedisKeys(): array
{
    $connection = app('redis')->connection('health');
    $prefix = (string) config('dynamic-throttle.prefix');
    $keys = (array) $connection->command('keys', [$prefix.'*']);
    sort($keys);
    $dumps = [];

    foreach ($keys as $key) {
        $dumps[(string) $key] = $connection->command('dump', [(string) $key]);
    }

    return $dumps;
}

it('AC-15: records the hour\'s requests, and each client by its ip identity, from the requests themselves', function (string $client, string $host, int $port): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    Throttling::route('api/x');

    foreach (['203.0.113.7', '203.0.113.7', '198.51.100.1'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('api/x')->assertOk();
    }

    $buffer = Telemetry::buffer();
    $hour = Telemetry::hour(Carbon::now()->getTimestamp());

    expect($buffer->requests($hour))->toBe(3)
        ->and($buffer->topClients($hour, 10))->toBe([Telemetry::ipClient('203.0.113.7') => 2, Telemetry::ipClient('198.51.100.1') => 1]);
})->with('health clients and services');

it('AC-15: counts two IPv6 clients of one /64 as one client, as the ip scope groups them', function (string $client, string $host, int $port): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    Throttling::route('api/x');

    foreach (['2001:db8::1', '2001:db8::2'] as $ip) {
        $this->withServerVariables(['REMOTE_ADDR' => $ip])->get('api/x')->assertOk();
    }

    $hour = Telemetry::hour(Carbon::now()->getTimestamp());

    expect(Telemetry::buffer()->topClients($hour, 10))->toBe([Telemetry::ipClient('2001:db8::/64') => 2]);
})->with('health clients and services');

it('AC-17: warns when one client sent half or more of at least 1,000 requests over the current and the previous hour', function (string $client, string $host, int $port): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    // The client's requests in the two hours add up.
    healthTraffic('10:30', 300, '203.0.113.7');
    healthTraffic('11:00', 400, '203.0.113.7');
    healthTraffic('10:45', 300, '198.51.100.1');
    // Before the previous hour: not counted.
    healthTraffic('09:30', 5_000, '198.51.100.1');

    [$status, $message] = Health::check('dominant-ip');

    expect($status)->toBe('warning')
        ->and($message)->toContain('700 of 1,000')->toContain('70%')->toContain(Telemetry::ipClient('203.0.113.7'))->toContain('trusted proxies');
})->with('health clients and services');

it('AC-17: is ok below half, or with too few requests to tell', function (string $client, string $host, int $port, int $top, array $others): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    healthTraffic('10:30', $top, '203.0.113.7');

    foreach ($others as $n => $sent) {
        healthTraffic('10:30', $sent, '198.51.100.'.($n + 1));
    }

    expect(Health::status('dominant-ip'))->toBe('ok');
})->with('health clients and services')->with([
    // Three others, since one would send the 600 left, and so dominate.
    '400 of 1,000' => [400, [200, 200, 200]],
    '700 of 900' => [700, [200]],
]);

it('AC-17: judges the traffic on 1,000 requests recorded, not on what they stand for, so that sampling never warns on a handful', function (string $client, string $host, int $port, float $rate, array $sent, string $status): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    config()->set('dynamic-throttle.telemetry.sample_rate', $rate);

    foreach ($sent as $n => $weight) {
        healthTraffic('10:30', $weight, '203.0.113.'.($n + 1));
    }

    expect(Health::status('dominant-ip'))->toBe($status);
})->with('health clients and services')->with([
    'one request at 0.001, which stands for 1,000' => [0.001, [1_000], 'ok'],
    'two at 0.002, from two clients, half each' => [0.002, [500, 500], 'ok'],
    'as many at 0.5 as 1,000 recorded, from one client' => [0.5, [2_000], 'warning'],
]);

it('AC-17: is skipped when Redis cannot be reached, saying so', function (string $client): void {
    healthOnRedis($client, '127.0.0.1', 1);
    Throttling::rule();

    [$status, $message] = Health::check('dominant-ip');

    expect($status)->toBe('skipped')
        ->and($message)->toContain('cannot be read');
})->with(['phpredis', 'predis']);

it('AC-3: changes no key in Redis', function (string $client, string $host, int $port): void {
    healthOnRedis($client, $host, $port);
    Throttling::rule();
    Throttling::route('api/x');
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('api/x')->assertOk();
    Telemetry::buffer()->markFlushed(Telemetry::minute(Carbon::now()->getTimestamp()) - 2);
    $before = healthRedisKeys();

    Health::report();
    Health::text();

    expect($before)->not->toBe([])
        ->and(healthRedisKeys())->toBe($before);
})->with('health clients and services');
