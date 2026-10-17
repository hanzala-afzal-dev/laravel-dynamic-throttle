<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Predis\Client as PredisClient;

covers(RedisTelemetryBuffer::class);

/*
| SPEC-015: what only the Redis driver's buffer does. One hash per minute, an
| offender set and a HyperLogLog per rule and hour, and one script call per
| request outside Redis Cluster (AC-3 to AC-6). SPEC-016 adds the hour's
| traffic: a sorted set of the clients and a count of the requests (AC-15).
*/

dataset('telemetry clients and services', function (): iterable {
    foreach (['phpredis', 'predis'] as $client) {
        yield "{$client}, Redis" => [$client, (string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)];
        yield "{$client}, Valkey" => [$client, (string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)];
    }
});

/**
 * A Redis buffer under a prefix of its own, whose connection reports every command it
 * sends to $commands.
 *
 * @param  list<string>  $commands
 * @return array{RedisTelemetryBuffer, Connection, string} the buffer, its connection, and its prefix
 */
function redisTelemetry(string $client, string $host, int $port, array &$commands = []): array
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0, 'read_write_timeout' => 2.0],
    ]);
    $connection = $manager->connection('default');
    $events = new Dispatcher(new Container);
    $events->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });
    $connection->setEventDispatcher($events);
    $prefix = 'dt-rtel-'.bin2hex(random_bytes(4)).':';

    return [new RedisTelemetryBuffer($manager, 'default', new KeyBuilder($prefix)), $connection, $prefix];
}

it('AC-3: writes a request\'s telemetry in one script call, whatever the number of rules', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer] = redisTelemetry($client, $host, $port, $commands);
    $minute = Telemetry::minute(1_800_000_000);
    // Redis may not have the script yet: the first call loads it.
    $buffer->record($minute, 1, [Telemetry::counted('warm')]);
    $sent = [];

    foreach ([1, 3, 10] as $size) {
        $commands = [];
        $buffer->record($minute, 1, array_map(
            fn (int $rule): TelemetryRecord => $rule % 2 === 0 ? Telemetry::blocked((string) $rule, "client {$rule}") : Telemetry::wouldBlock((string) $rule, "client {$rule}"),
            range(1, $size),
        ));
        $sent[$size] = $commands;
    }

    expect($sent)->toBe([1 => ['evalsha'], 3 => ['evalsha'], 10 => ['evalsha']]);
})->with('telemetry clients and services');

it('AC-3: writes one script call per key on a Redis Cluster connection, where a script may only touch one hash slot, with the same numbers', function (string $client, string $host, int $port): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    // One node behind a cluster connection, as SPEC-027's cluster test does.
    if ($client === 'phpredis') {
        $phpredis = new Redis;
        $phpredis->connect($host, $port, 2.0);
        $connection = new PhpRedisClusterConnection($phpredis);
    } else {
        $connection = new PredisClusterConnection(new PredisClient(['host' => $host, 'port' => $port]));
    }

    $commands = [];
    $events = new Dispatcher(new Container);
    $events->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });
    $connection->setEventDispatcher($events);
    $factory = new readonly class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }
    };
    $prefix = 'dt-rtel-'.bin2hex(random_bytes(4)).':';
    $buffer = new RedisTelemetryBuffer($factory, 'default', new KeyBuilder($prefix));
    $minute = Telemetry::minute(1_800_000_000);
    $hour = Telemetry::hour(1_800_000_000);
    // Redis may not have the script yet: the first call loads it.
    $buffer->record($minute - 1, 1, [Telemetry::counted('warm')]);
    $commands = [];

    $buffer->record($minute, 2, [Telemetry::counted('1'), Telemetry::blocked('2', 'A'), Telemetry::wouldBlock('3', 'B')]);
    $sent = $commands;
    $ttls = array_map(
        fn (string $key): int => (int) $connection->command('ttl', [$prefix.$key]),
        ["t:{$minute}", "t:o:2:{$hour}", "t:u:2:{$hour}", "t:o:3:{$hour}", "t:u:3:{$hour}"],
    );

    // The minute's hash, then rule 2's offenders and clients, then rule 3's: five keys, five calls.
    expect($sent)->toBe(['evalsha', 'evalsha', 'evalsha', 'evalsha', 'evalsha'])
        ->and(Telemetry::counts($minute, ['1', '2', '3'], $buffer))->toEqual(['1' => [2, 0, 0], '2' => [2, 2, 0], '3' => [2, 0, 2]])
        ->and([$buffer->offenders('2', $hour), $buffer->offenders('3', $hour)])->toBe([[hash('sha256', 'A') => 2], [hash('sha256', 'B') => 2]])
        ->and([$buffer->clients('2', $hour), $buffer->clients('3', $hour)])->toBe([1, 1])
        // Each call carries the TTL, so every key expires as the one call's keys do.
        ->and(array_filter($ttls, fn (int $ttl): bool => $ttl <= 7_190 || $ttl > 7_200))->toBe([]);
})->with('telemetry clients and services');

it('AC-4: gives the top 100 clients a rule blocked or would have blocked in an hour, most first, with their counts', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;

    // Client k is blocked k times, as one request that stands for k, by the rule itself or by its dry-run twin.
    foreach (range(1, 150) as $k) {
        $buffer->record(Telemetry::minute($time), $k, [$k % 2 === 0 ? Telemetry::blocked('1', "client {$k}") : Telemetry::wouldBlock('1', "client {$k}")]);
    }

    $expected = [];

    foreach (range(150, 51) as $k) {
        $expected[hash('sha256', "client {$k}")] = $k;
    }

    expect($buffer->offenders('1', Telemetry::hour($time)))->toBe($expected)
        ->and($buffer->offenders('1', Telemetry::hour($time), 3))->toBe(array_slice($expected, 0, 3, true))
        ->and($buffer->offenders('2', Telemetry::hour($time)))->toBe([]);
})->with('telemetry clients and services');

it('AC-4: counts a sampled request\'s offender by its weight, and leaves counted requests out', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);

    $buffer->record($minute, 4, [Telemetry::blocked('1', 'A'), Telemetry::counted('2', 'B')]);
    $buffer->record($minute, 1, [Telemetry::blocked('1', 'A')]);

    expect($buffer->offenders('1', Telemetry::hour(1_800_000_000)))->toBe([hash('sha256', 'A') => 5])
        ->and($buffer->offenders('2', Telemetry::hour(1_800_000_000)))->toBe([]);
})->with('telemetry clients and services');

it('AC-4: never keeps more than 200 offenders for a rule and hour, under an attack from 1,000 clients', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);
    $largest = 0;

    foreach (range(1, 1_000) as $k) {
        $buffer->record($minute, 1, [Telemetry::blocked('1', "client {$k}")]);
        $largest = max($largest, (int) $connection->command('zcard', [$prefix.'t:o:1:'.Telemetry::hour(1_800_000_000)]));
    }

    expect($largest)->toBe(200)
        ->and($buffer->offenders('1', Telemetry::hour(1_800_000_000)))->toHaveCount(100);
})->with('telemetry clients and services');

it('AC-5: counts the different clients a rule blocked or would have blocked in an hour', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;

    foreach (range(1, 10) as $n) {
        $buffer->record(Telemetry::minute($time + $n), 1, [Telemetry::blocked('1', 'client '.($n % 3)), Telemetry::counted('2', "client {$n}")]);
    }

    expect([$buffer->clients('1', Telemetry::hour($time)), $buffer->clients('2', Telemetry::hour($time)), $buffer->clients('1', Telemetry::hour($time) + 1)])
        ->toBe([3, 0, 0]);
})->with('telemetry clients and services');

it('AC-6: lets every key it writes expire 7,200 seconds after it is first written', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;
    $minute = Telemetry::minute($time);
    $hour = Telemetry::hour($time);
    $buffer->record($minute, 1, [Telemetry::blocked('1')]);
    // A later write never pushes the expiry back.
    $connection->command('expire', [$prefix."t:{$minute}", 7_000]);
    $buffer->record($minute, 1, [Telemetry::wouldBlock('1')]);

    $ttls = array_map(
        fn (string $key): int => (int) $connection->command('ttl', [$prefix.$key]),
        ["t:{$minute}", "t:o:1:{$hour}", "t:u:1:{$hour}"],
    );

    expect($ttls[0])->toBeGreaterThan(6_990)->toBeLessThanOrEqual(7_000)
        ->and($ttls[1])->toBeGreaterThan(7_190)->toBeLessThanOrEqual(7_200)
        ->and($ttls[2])->toBeGreaterThan(7_190)->toBeLessThanOrEqual(7_200);
})->with('telemetry clients and services');

it('AC-15: reads every rule the minute holds, whatever rules it is asked for', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);
    $buffer->record($minute, 1, [Telemetry::counted('1'), Telemetry::blocked('7')]);

    // Rule 7 was deleted since: the hash still names it.
    expect(Telemetry::counts($minute, ['1'], $buffer))->toEqual(['1' => [1, 0, 0], '7' => [1, 1, 0]]);
})->with('telemetry clients and services');

it('AC-8: reports a Redis it cannot reach as unavailable, when recording and when reading', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.5, 'read_timeout' => 0.5],
    ]);
    $buffer = new RedisTelemetryBuffer($manager, 'default', new KeyBuilder('dt:'));
    $minute = Telemetry::minute(1_800_000_000);

    expect(fn () => $buffer->record($minute, 1, [Telemetry::counted('1')]))->toThrow(StorageUnavailableException::class)
        ->and(fn (): array => $buffer->counts($minute, ['1']))->toThrow(StorageUnavailableException::class)
        ->and(fn (): ?int => $buffer->flushedThrough())->toThrow(StorageUnavailableException::class);
})->with(['phpredis', 'predis']);

/**
 * A Redis buffer on a Redis Cluster connection to one node, as SPEC-027's cluster
 * test makes one, whose connection reports every command it sends to $commands.
 *
 * @param  list<string>  $commands
 * @return array{RedisTelemetryBuffer, Connection, string} the buffer, its connection, and its prefix
 */
function clusterTelemetry(string $client, string $host, int $port, array &$commands = []): array
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    if ($client === 'phpredis') {
        $phpredis = new Redis;
        $phpredis->connect($host, $port, 2.0);
        $connection = new PhpRedisClusterConnection($phpredis);
    } else {
        $connection = new PredisClusterConnection(new PredisClient(['host' => $host, 'port' => $port]));
    }

    $events = new Dispatcher(new Container);
    $events->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });
    $connection->setEventDispatcher($events);
    $factory = new readonly class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }
    };
    $prefix = 'dt-rtel-'.bin2hex(random_bytes(4)).':';

    return [new RedisTelemetryBuffer($factory, 'default', new KeyBuilder($prefix)), $connection, $prefix];
}

it('SPEC-016 AC-15: counts an hour\'s recorded requests and each client\'s, most first, in the request\'s one script call', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer] = redisTelemetry($client, $host, $port, $commands);
    $time = 1_800_000_000;
    $hour = Telemetry::hour($time);
    $a = hash('sha256', 'ip:203.0.113.7');
    $b = hash('sha256', 'ip:198.51.100.1');
    // Redis may not have the script yet: the first call loads it, two hours before.
    $buffer->record(Telemetry::minute($time) - 120, 1, [Telemetry::counted('warm')], $a);
    $commands = [];

    $buffer->record(Telemetry::minute($time), 1, [Telemetry::counted('1'), Telemetry::blocked('2', 'X')], $a);
    $buffer->record(Telemetry::minute($time) + 1, 1, [Telemetry::skipped('1'), Telemetry::ignored('2')], $a);
    $buffer->record(Telemetry::minute($time) + 2, 1, [Telemetry::guest('1', 'Y')], $b);

    expect($commands)->toBe(['evalsha', 'evalsha', 'evalsha'])
        ->and($buffer->requests($hour))->toBe(3)
        ->and($buffer->topClients($hour, 10))->toBe([$a => 2, $b => 1])
        ->and($buffer->topClients($hour))->toBe([$a => 2])
        // An hour without traffic: none, which a driver that keeps traffic counts as 0.
        ->and([$buffer->requests($hour + 1), $buffer->topClients($hour + 1, 10)])->toBe([0, []]);
})->with('telemetry clients and services');

it('SPEC-016 AC-15: weighs a sampled request\'s traffic, and leaves a request without a client out of it', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;
    $a = hash('sha256', 'ip:203.0.113.7');

    $buffer->record(Telemetry::minute($time), 4, [Telemetry::counted('1')], $a);
    // The client IP could not be read.
    $buffer->record(Telemetry::minute($time), 1, [Telemetry::counted('1')]);

    expect([$buffer->requests(Telemetry::hour($time)), $buffer->topClients(Telemetry::hour($time), 10)])->toBe([4, [$a => 4]])
        ->and(Telemetry::counts(Telemetry::minute($time), ['1'], $buffer))->toEqual(['1' => [5, 0, 0]]);
})->with('telemetry clients and services');

it('SPEC-016 AC-15: keeps an hour\'s clients to the top 100 as the offenders are, and still counts every request', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;
    $hour = Telemetry::hour($time);
    $largest = 0;

    // Client k sends k requests, as one request that stands for k.
    foreach (range(1, 250) as $k) {
        $buffer->record(Telemetry::minute($time), $k, [Telemetry::counted('1')], hash('sha256', "ip:client {$k}"));
        $largest = max($largest, (int) $connection->command('zcard', [$prefix."t:ip:{$hour}"]));
    }

    $top = $buffer->topClients($hour, 100);

    expect($largest)->toBe(200)
        ->and($top)->toHaveCount(100)
        ->and(array_slice($top, 0, 2, true))->toBe([hash('sha256', 'ip:client 250') => 250, hash('sha256', 'ip:client 249') => 249])
        ->and($buffer->requests($hour))->toBe(250 * 251 / 2);
})->with('telemetry clients and services');

it('SPEC-016 AC-15: lets the hour\'s traffic keys expire 7,200 seconds after they are first written', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $time = 1_800_000_000;
    $hour = Telemetry::hour($time);
    $a = hash('sha256', 'ip:203.0.113.7');
    $buffer->record(Telemetry::minute($time), 1, [Telemetry::counted('1')], $a);
    // A later write never pushes the expiry back.
    $connection->command('expire', [$prefix."t:ip:{$hour}", 7_000]);
    $buffer->record(Telemetry::minute($time), 1, [Telemetry::counted('1')], $a);

    $ttls = array_map(fn (string $key): int => (int) $connection->command('ttl', [$prefix.$key]), ["t:ip:{$hour}", "t:n:{$hour}"]);

    expect($ttls[0])->toBeGreaterThan(6_990)->toBeLessThanOrEqual(7_000)
        ->and($ttls[1])->toBeGreaterThan(7_190)->toBeLessThanOrEqual(7_200);
})->with('telemetry clients and services');

it('SPEC-016 AC-15: writes the hour\'s traffic with one call per key on Redis Cluster, with the same numbers', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer, $connection, $prefix] = clusterTelemetry($client, $host, $port, $commands);
    $time = 1_800_000_000;
    $minute = Telemetry::minute($time);
    $hour = Telemetry::hour($time);
    $a = hash('sha256', 'ip:203.0.113.7');
    // Redis may not have the script yet: the first call loads it, two hours before.
    $buffer->record($minute - 120, 1, [Telemetry::counted('warm')], $a);
    $commands = [];

    $buffer->record($minute, 2, [Telemetry::counted('1'), Telemetry::skipped('2')], $a);
    $sent = $commands;
    $ttls = array_map(fn (string $key): int => (int) $connection->command('ttl', [$prefix.$key]), ["t:{$minute}", "t:ip:{$hour}", "t:n:{$hour}"]);

    // The minute's hash, then the hour's clients and requests: three keys, three calls.
    expect($sent)->toBe(['evalsha', 'evalsha', 'evalsha'])
        ->and(Telemetry::all($minute, ['1', '2'], $buffer))->toEqual(['1' => [2, 0, 0, 0, 0, 0], '2' => [0, 0, 0, 0, 2, 0]])
        ->and([$buffer->requests($hour), $buffer->topClients($hour, 10)])->toBe([2, [$a => 2]])
        ->and(array_filter($ttls, fn (int $ttl): bool => $ttl <= 7_190 || $ttl > 7_200))->toBe([]);
})->with('telemetry clients and services');

it('SPEC-016 AC-19: reports a Redis it cannot reach as unavailable when reading the traffic', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.5, 'read_timeout' => 0.5],
    ]);
    $buffer = new RedisTelemetryBuffer($manager, 'default', new KeyBuilder('dt:'));

    expect(fn (): int => $buffer->requests(500_000))->toThrow(StorageUnavailableException::class)
        ->and(fn (): array => $buffer->topClients(500_000))->toThrow(StorageUnavailableException::class);
})->with(['phpredis', 'predis']);

/*
| SPEC-030: the live feed, one list a minute beside the telemetry.
*/

it('SPEC-030 AC-4: writes a request\'s feed entries in its one script call', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer] = redisTelemetry($client, $host, $port, $commands);
    $minute = Telemetry::minute(1_800_000_000);
    // Redis may not have the script yet: the first call loads it.
    $buffer->record($minute, 1, [Telemetry::counted('warm')]);
    $commands = [];

    $buffer->record($minute, 1, [Telemetry::blocked('1'), Telemetry::wouldBlock('2')], hash('sha256', 'client'), [Telemetry::entry('1'), Telemetry::entry('2', outcome: TelemetryOutcome::WouldBlock)]);

    expect($commands)->toBe(['evalsha'])->and($buffer->feed($minute))->toHaveCount(2);
})->with('telemetry clients and services');

it('SPEC-030 AC-4: keeps a minute\'s newest 500 entries, newest first', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);

    foreach (array_chunk(range(1, 520), 40) as $times) {
        $buffer->record($minute, 1, [Telemetry::blocked('1')], null, array_map(fn (int $time): FeedEntry => Telemetry::entry('1', time: 1_800_000_000_000 + $time), $times));
    }

    $times = array_map(fn (FeedEntry $entry): int => $entry->time - 1_800_000_000_000, $buffer->feed($minute) ?? []);

    expect($times)->toBe(range(520, 21));
})->with('telemetry clients and services');

it('SPEC-030 AC-4: lets a minute\'s list expire 3,660 seconds after it is created, however often it is written', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);
    $buffer->record($minute, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1')]);
    $first = (int) $connection->command('ttl', [$prefix."t:f:{$minute}"]);
    // A later write never pushes the expiry back.
    $connection->command('expire', [$prefix."t:f:{$minute}", 3_000]);
    $buffer->record($minute, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1', time: 1_800_000_001_000)]);

    expect($first)->toBeGreaterThan(3_650)->toBeLessThanOrEqual(3_660)
        ->and((int) $connection->command('ttl', [$prefix."t:f:{$minute}"]))->toBeGreaterThan(2_990)->toBeLessThanOrEqual(3_000);
})->with('telemetry clients and services');

it('SPEC-030 AC-4: reads the last 60 minutes\' newest 500, newest first, and only those after since', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $now = Telemetry::minute(1_800_000_000);
    // 400 entries in each of the current and the previous minute, and one 60 minutes ago.
    foreach ([$now, $now - 1] as $minute) {
        foreach (array_chunk(range(0, 399), 100) as $offsets) {
            $buffer->record($minute, 1, [Telemetry::blocked('1')], null, array_map(fn (int $offset): FeedEntry => Telemetry::entry('1', time: $minute * 60_000 + $offset), $offsets));
        }
    }
    $buffer->record($now - 60, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1', time: ($now - 60) * 60_000)]);

    $all = array_map(fn (FeedEntry $entry): int => $entry->time, $buffer->feed($now) ?? []);
    $since = array_map(fn (FeedEntry $entry): int => $entry->time, $buffer->feed($now, $now * 60_000 + 389) ?? []);

    expect([count($all), $all[0], $all[399], $all[400], $all[499]])->toBe([500, $now * 60_000 + 399, $now * 60_000, ($now - 1) * 60_000 + 399, ($now - 1) * 60_000 + 300])
        ->and($since)->toBe(array_map(fn (int $offset): int => $now * 60_000 + $offset, range(399, 390)));
})->with('telemetry clients and services');

it('SPEC-030 AC-4: skips an entry it cannot read', function (string $client, string $host, int $port): void {
    [$buffer, $connection, $prefix] = redisTelemetry($client, $host, $port);
    $minute = Telemetry::minute(1_800_000_000);
    $buffer->record($minute, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1')]);
    $connection->command('lpush', [$prefix."t:f:{$minute}", 'not an entry']);

    expect($buffer->feed($minute))->toEqual([Telemetry::entry('1')]);
})->with('telemetry clients and services');

it('SPEC-030 AC-6: writes the feed in a call of its own on a Redis Cluster connection, with the same entries', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer, $connection, $prefix] = clusterTelemetry($client, $host, $port, $commands);
    $minute = Telemetry::minute(1_800_000_000);
    $buffer->record($minute - 1, 1, [Telemetry::counted('warm')]);
    $commands = [];

    $buffer->record($minute, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1')]);
    $sent = $commands;

    // The minute's hash, the rule's offenders and clients, and the feed: four keys, four calls.
    expect($sent)->toBe(['evalsha', 'evalsha', 'evalsha', 'evalsha'])
        ->and($buffer->feed($minute))->toEqual([Telemetry::entry('1')])
        ->and((int) $connection->command('ttl', [$prefix."t:f:{$minute}"]))->toBeGreaterThan(3_650)->toBeLessThanOrEqual(3_660);
})->with('telemetry clients and services');

it('SPEC-030 AC-4: reads the 60 minutes up to the given one, and not the minute before them', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $now = Telemetry::minute(1_800_000_000);

    foreach ([$now + 1, $now, $now - 59, $now - 60] as $minute) {
        $buffer->record($minute, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1', time: $minute * 60_000)]);
    }

    expect(array_map(fn (FeedEntry $entry): int => intdiv($entry->time, 60_000), $buffer->feed($now) ?? []))->toBe([$now, $now - 59]);
})->with('telemetry clients and services');

it('SPEC-030 AC-4: orders entries by their time, whatever minute a server with a skewed clock filed them in', function (string $client, string $host, int $port): void {
    [$buffer] = redisTelemetry($client, $host, $port);
    $now = Telemetry::minute(1_800_000_000);
    $buffer->record($now, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1', time: $now * 60_000 + 1)]);
    // A server whose clock runs ahead filed this one a minute earlier, with a later time.
    $buffer->record($now - 1, 1, [Telemetry::blocked('1')], null, [Telemetry::entry('1', time: $now * 60_000 + 2)]);

    expect(array_map(fn (FeedEntry $entry): int => $entry->time - $now * 60_000, $buffer->feed($now) ?? []))->toBe([2, 1]);
})->with('telemetry clients and services');

it('SPEC-030 AC-4 and AC-6: reads the feed in one script call, and one per minute on a Redis Cluster connection', function (string $client, string $host, int $port): void {
    $commands = [];
    [$buffer] = redisTelemetry($client, $host, $port, $commands);
    $clusterCommands = [];
    [$cluster] = clusterTelemetry($client, $host, $port, $clusterCommands);
    $minute = Telemetry::minute(1_800_000_000);
    // Redis may not have the script yet: the first read loads it.
    $buffer->feed($minute);
    $cluster->feed($minute);
    [$commands, $clusterCommands] = [[], []];

    $buffer->feed($minute);
    $cluster->feed($minute);

    expect($commands)->toBe(['evalsha'])->and($clusterCommands)->toBe(array_fill(0, 60, 'evalsha'));
})->with('telemetry clients and services');
