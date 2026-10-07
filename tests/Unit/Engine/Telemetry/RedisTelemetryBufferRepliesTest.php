<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Mockery\Container;
use Predis\Client as PredisClient;
use Predis\Response\Error as PredisError;
use Predis\Response\ServerException as PredisServerException;

covers(RedisTelemetryBuffer::class);

/*
| SPEC-015 and SPEC-016: the Redis buffer with stubbed clients, as
| RedisLimiterStore's replies test does (ADR-0016 allows mocks for this): what
| each script receives, the EVAL fallback, the errors it names, and the
| replies it refuses. Against a real Redis, another process can load a script at any
| moment, so only stubs make these paths certain.
*/

afterEach(function (): void {
    if (($container = Mockery::getContainer()) instanceof Container) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Mockery::close();
});

function stubbedTelemetry(Connection $connection): RedisTelemetryBuffer
{
    $factory = new readonly class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }
    };

    return new RedisTelemetryBuffer($factory, 'default', new KeyBuilder('dt:'));
}

/**
 * A phpredis client that answers EVALSHA with these replies in turn, and EVAL with
 * $eval, and records what it receives, naming each script by its SHA-1. Mockery
 * cannot mock a method named eval, a reserved word.
 *
 * @param  list<mixed>  $evalsha
 */
function telemetryPhpRedis(array $evalsha, ?string $lastError = null, mixed $eval = null): Redis
{
    return new class($evalsha, $lastError, $eval) extends Redis
    {
        /** @var list<array{string, string, array<mixed>, int}> */
        public array $received = [];

        /** @param  list<mixed>  $evalshaReplies */
        public function __construct(private array $evalshaReplies, private readonly ?string $lastError, private readonly mixed $evalReply)
        {
            // Never connects, so the parent constructor is not needed.
        }

        public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
        {
            $this->received[] = ['evalsha', $sha1, $args, $num_keys];

            return array_shift($this->evalshaReplies);
        }

        public function eval(string $script, array $args = [], int $num_keys = 0): mixed
        {
            $this->received[] = ['eval', sha1($script), $args, $num_keys];

            return $this->evalReply;
        }

        public function getLastError(): ?string
        {
            return $this->lastError;
        }
    };
}

/**
 * The messages of an exception and of every exception before it.
 */
function telemetryExceptionChain(Throwable $exception): string
{
    $messages = [];

    for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
        $messages[] = $current->getMessage();
    }

    return implode(' <- ', $messages);
}

/**
 * A Redis factory that fails the test's request if anything asks it for a connection.
 */
function telemetryUnreachable(): RedisFactory
{
    return new class implements RedisFactory
    {
        public int $connections = 0;

        public function connection($name = null): Connection
        {
            $this->connections++;

            throw new RuntimeException('Redis was called.');
        }
    };
}

it('AC-3: sends the minute\'s hash, then each blocking rule\'s offenders and clients, as keys, then the weight, the TTL and each key\'s kind and argument', function (): void {
    $client = telemetryPhpRedis([5]);
    $minute = Telemetry::minute(1_800_000_000);
    $a = hash('sha256', 'A');
    $b = hash('sha256', 'B');

    stubbedTelemetry(new PhpRedisConnection($client))->record($minute, 3, [Telemetry::counted('1'), Telemetry::blocked('2', 'A'), Telemetry::wouldBlock('3', 'B')]);

    expect($client->received)->toHaveCount(1)
        ->and([$client->received[0][0], $client->received[0][2], $client->received[0][3]])->toBe(['evalsha', [
            'dt:t:30000000', 'dt:t:o:2:500000', 'dt:t:u:2:500000', 'dt:t:o:3:500000', 'dt:t:u:3:500000',
            '3', '7200',
            'h', '1:hits 2:hits 2:blocks 3:hits 3:would_blocks',
            'o', $a, 'u', $a,
            'o', $b, 'u', $b,
        ], 5]);
});

it('SPEC-015: makes no call to Redis for a request without records', function (): void {
    $factory = telemetryUnreachable();

    (new RedisTelemetryBuffer($factory, 'default', new KeyBuilder('dt:')))->record(30_000_000, 1, []);

    expect($factory->connections)->toBe(0);
});

it('SPEC-015: reports a record reply other than the number of keys written as unavailable', function (mixed $reply): void {
    $buffer = stubbedTelemetry(new PhpRedisConnection(telemetryPhpRedis([$reply])));

    expect(fn () => $buffer->record(30_000_000, 1, [Telemetry::counted('1')]))
        ->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.');
})->with([
    'too many' => [2],
    'none' => [0],
    'a numeric string' => ['1'],
    'a status reply' => ['OK'],
    'a list' => [[1]],
]);

it('AC-8: falls back to EVAL of the same script once when phpredis reports NOSCRIPT', function (): void {
    $client = telemetryPhpRedis([false], lastError: 'NOSCRIPT No matching script. Please use EVAL.', eval: 1);

    stubbedTelemetry(new PhpRedisConnection($client))->record(30_000_000, 1, [Telemetry::counted('1')]);

    $arguments = ['dt:t:30000000', '1', '7200', 'h', '1:hits'];
    expect(array_map(fn (array $call): array => [$call[0], $call[2], $call[3]], $client->received))->toBe([
        ['evalsha', $arguments, 1],
        ['eval', $arguments, 1],
    ])
        ->and($client->received[1][1])->toBe($client->received[0][1]);
});

it('AC-8: never falls back to EVAL for another phpredis error, and names it', function (): void {
    $client = telemetryPhpRedis([false], lastError: 'ERR Error running script, NOSCRIPT is not the cause');

    expect(fn () => stubbedTelemetry(new PhpRedisConnection($client))->record(30_000_000, 1, [Telemetry::counted('1')]))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect(telemetryExceptionChain($exception))->toBe('The Redis telemetry buffer could not record the request. <- Redis rejected the telemetry script: ERR Error running script, NOSCRIPT is not the cause');
        })
        ->and(array_column($client->received, 0))->toBe(['evalsha']);
});

it('AC-8: falls back to EVAL once when predis reports NOSCRIPT', function (Throwable|PredisError $noscript): void {
    $client = Mockery::mock(PredisClient::class);
    $client->shouldReceive('evalsha')->once()->andReturnUsing(function () use ($noscript): PredisError {
        if ($noscript instanceof Throwable) {
            throw $noscript;
        }

        return $noscript;
    });
    $client->shouldReceive('eval')->once()
        ->withArgs(fn (string $script, int $keys, string ...$parameters): bool => str_contains($script, "redis.call('HINCRBY'") && $keys === 1 && $parameters === ['dt:t:30000000', '1', '7200', 'h', '1:hits'])
        ->andReturn(1);

    stubbedTelemetry(new PredisConnection($client))->record(30_000_000, 1, [Telemetry::counted('1')]);
})->with([
    'with exceptions (a ServerException)' => [new PredisServerException('NOSCRIPT No matching script. Please use EVAL.')],
    'with exceptions off (an error reply)' => [new PredisError('NOSCRIPT No matching script. Please use EVAL.')],
]);

it('AC-8: never falls back to EVAL for another predis error, and names it', function (Throwable|PredisError $error, string $chain): void {
    $client = Mockery::mock(PredisClient::class);
    $client->shouldReceive('evalsha')->once()->andReturnUsing(function () use ($error): PredisError {
        if ($error instanceof Throwable) {
            throw $error;
        }

        return $error;
    });
    $client->shouldReceive('eval')->never();

    expect(fn () => stubbedTelemetry(new PredisConnection($client))->record(30_000_000, 1, [Telemetry::counted('1')]))
        ->toThrow(function (StorageUnavailableException $exception) use ($chain): void {
            expect(telemetryExceptionChain($exception))->toBe($chain);
        });
})->with([
    // The ServerException itself is the previous exception.
    'with exceptions (a ServerException)' => [new PredisServerException('ERR Error running script'), 'The Redis telemetry buffer could not record the request. <- ERR Error running script'],
    'with exceptions off (an error reply)' => [new PredisError('ERR Error running script'), 'The Redis telemetry buffer could not record the request. <- Redis rejected the telemetry script: ERR Error running script'],
]);

it('AC-8: reports a connection that is neither phpredis nor predis as unavailable', function (): void {
    $connection = new class extends Connection
    {
        public function __construct()
        {
            $this->client = new stdClass;
        }

        public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
    };

    expect(fn () => stubbedTelemetry($connection)->record(30_000_000, 1, [Telemetry::counted('1')]))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->getPrevious()?->getMessage())->toBe('Unsupported Redis connection '.Connection::class.'@anonymous; use phpredis or predis.');
        });
});

it('AC-8: reports a phpredis connection around another client as unavailable', function (): void {
    // Answers EVALSHA like phpredis does on an error, but is no phpredis client.
    $client = new class
    {
        public function evalsha(string $sha1, array $args = [], int $num_keys = 0): bool
        {
            return false;
        }
    };

    expect(fn () => stubbedTelemetry(new PhpRedisConnection($client))->record(30_000_000, 1, [Telemetry::counted('1')]))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->getPrevious()?->getMessage())->toBe('Unsupported phpredis client class@anonymous.');
        });
});

it('AC-1: reads a minute\'s hash, every rule in it, with the metrics it lacks as 0 and a count below 0 as 0', function (): void {
    // Rule 9 has blocks without hits, which no script writes, but the hash could hold.
    $client = telemetryPhpRedis([['1:hits', '3', '1:blocks', '1', '7:hits', '2', '7:would_blocks', '-4', '9:blocks', '1']]);

    $counts = Telemetry::counts(30_000_000, ['1'], stubbedTelemetry(new PhpRedisConnection($client)));

    expect($counts)->toBe(['1' => [3, 1, 0], '7' => [2, 0, 0], '9' => [0, 1, 0]])
        ->and([$client->received[0][2], $client->received[0][3]])->toBe([['dt:t:30000000'], 1]);
});

it('SPEC-015: reports a minute\'s reply it cannot read as unavailable', function (mixed $reply): void {
    $buffer = stubbedTelemetry(new PhpRedisConnection(telemetryPhpRedis([$reply])));

    expect(fn (): array => $buffer->counts(30_000_000, ['1']))
        ->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.');
})->with([
    'a field without its value' => [['1:hits', '3', '1:blocks']],
    'not a list' => [[1 => '1:hits', 2 => '3']],
    'a field that is not a string' => [[7, '3']],
    'a field without a metric' => [['hits', '3']],
    'an unknown metric' => [['1:misses', '3']],
    'a count that is not a number' => [['1:hits', 'many']],
    'a status reply' => ['OK'],
]);

it('AC-4: reads as many offenders as it is asked for, most first, and sends how many', function (): void {
    $a = hash('sha256', 'A');
    $b = hash('sha256', 'B');
    $client = telemetryPhpRedis([[$a, '5'], [$a, '5', $b, '2']]);
    $buffer = stubbedTelemetry(new PhpRedisConnection($client));

    expect([$buffer->offenders('2', 500_000, 1), $buffer->offenders('2', 500_000)])->toBe([[$a => 5], [$a => 5, $b => 2]])
        ->and(array_map(fn (array $call): array => [$call[2], $call[3]], $client->received))->toBe([
            [['dt:t:o:2:500000', '1'], 1],
            [['dt:t:o:2:500000', '100'], 1],
        ]);
});

it('AC-4: refuses to read fewer than 1 offender, before calling Redis', function (): void {
    $factory = telemetryUnreachable();

    expect(fn (): array => (new RedisTelemetryBuffer($factory, 'default', new KeyBuilder('dt:')))->offenders('2', 500_000, 0))
        ->toThrow(InvalidArgumentException::class, 'The offenders to read must be at least 1, got 0.')
        ->and($factory->connections)->toBe(0);
});

it('AC-4: reports an offenders reply it cannot read as unavailable', function (mixed $reply): void {
    $buffer = stubbedTelemetry(new PhpRedisConnection(telemetryPhpRedis([$reply])));

    expect(fn (): array => $buffer->offenders('2', 500_000))
        ->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.');
})->with([
    'a client without its count' => [[hash('sha256', 'A')]],
    'a count that is not a number' => [[hash('sha256', 'A'), 'many']],
]);

it('AC-5: reads an hour\'s distinct clients, and refuses a reply that is not an integer', function (): void {
    $client = telemetryPhpRedis([3, '3']);
    $buffer = stubbedTelemetry(new PhpRedisConnection($client));

    expect($buffer->clients('2', 500_000))->toBe(3)
        ->and(fn (): int => $buffer->clients('2', 500_000))->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.')
        ->and([$client->received[0][2], $client->received[0][3]])->toBe([['dt:t:u:2:500000'], 1]);
});

it('AC-14: keeps the last minute flushed as long as the numbers, and reads it back, or nothing when the store has none', function (): void {
    $client = telemetryPhpRedis([1, '30000005', '']);
    $buffer = stubbedTelemetry(new PhpRedisConnection($client));

    $buffer->markFlushed(30_000_005);

    expect([$buffer->flushedThrough(), $buffer->flushedThrough()])->toBe([30_000_005, null])
        ->and(array_map(fn (array $call): array => [$call[2], $call[3]], $client->received))->toBe([
            [['dt:t:flushed', '30000005', '7200'], 1],
            [['dt:t:flushed'], 1],
            [['dt:t:flushed'], 1],
        ]);
});

it('SPEC-016 AC-14 and AC-15: sends the new metrics as the minute\'s fields, and the hour\'s clients and requests as the last keys', function (): void {
    $client = telemetryPhpRedis([5]);
    $minute = Telemetry::minute(1_800_000_000);
    $a = hash('sha256', 'A');
    $ip = hash('sha256', 'ip:203.0.113.7');

    stubbedTelemetry(new PhpRedisConnection($client))->record($minute, 3, [
        Telemetry::guest('1'),
        Telemetry::skipped('2'),
        Telemetry::ignored('3'),
        Telemetry::guest('4', 'A', TelemetryOutcome::Blocked),
    ], $ip);

    expect($client->received)->toHaveCount(1)
        ->and([$client->received[0][2], $client->received[0][3]])->toBe([[
            'dt:t:30000000', 'dt:t:o:4:500000', 'dt:t:u:4:500000', 'dt:t:ip:500000', 'dt:t:n:500000',
            '3', '7200',
            'h', '1:hits 1:guests 2:skipped 3:ignored 4:hits 4:blocks 4:guests',
            'o', $a, 'u', $a,
            'o', $ip,
            'h', 'requests',
        ], 5]);
});

it('SPEC-016 AC-15: sends no traffic key for a request without a client', function (): void {
    $client = telemetryPhpRedis([1]);

    stubbedTelemetry(new PhpRedisConnection($client))->record(30_000_000, 1, [Telemetry::skipped('1')]);

    expect([$client->received[0][2], $client->received[0][3]])->toBe([['dt:t:30000000', '1', '7200', 'h', '1:skipped'], 1]);
});

it('SPEC-016 AC-15: reads an hour\'s top clients, as many as it is asked for, most first, and sends how many', function (): void {
    $a = hash('sha256', 'ip:203.0.113.7');
    $b = hash('sha256', 'ip:198.51.100.1');
    $client = telemetryPhpRedis([[$a, '5'], [$a, '5', $b, '2']]);
    $buffer = stubbedTelemetry(new PhpRedisConnection($client));

    expect([$buffer->topClients(500_000), $buffer->topClients(500_000, 10)])->toBe([[$a => 5], [$a => 5, $b => 2]])
        ->and(array_map(fn (array $call): array => [$call[2], $call[3]], $client->received))->toBe([
            [['dt:t:ip:500000', '1'], 1],
            [['dt:t:ip:500000', '10'], 1],
        ]);
});

it('SPEC-016 AC-15: refuses to read fewer than 1 top client, before calling Redis', function (): void {
    $factory = telemetryUnreachable();

    expect(fn (): array => (new RedisTelemetryBuffer($factory, 'default', new KeyBuilder('dt:')))->topClients(500_000, 0))
        ->toThrow(InvalidArgumentException::class, 'The top clients to read must be at least 1, got 0.')
        ->and($factory->connections)->toBe(0);
});

it('SPEC-016 AC-15: reports a top clients reply it cannot read as unavailable', function (mixed $reply): void {
    $buffer = stubbedTelemetry(new PhpRedisConnection(telemetryPhpRedis([$reply])));

    expect(fn (): array => $buffer->topClients(500_000))
        ->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.');
})->with([
    'a client without its count' => [[hash('sha256', 'A')]],
    'a count that is not a number' => [[hash('sha256', 'A'), 'many']],
    'a status reply' => ['OK'],
]);

it('SPEC-016 AC-15: reads an hour\'s requests, and refuses a reply that is not an integer', function (): void {
    $client = telemetryPhpRedis([7, 0, '7']);
    $buffer = stubbedTelemetry(new PhpRedisConnection($client));

    expect([$buffer->requests(500_000), $buffer->requests(500_001)])->toBe([7, 0])
        ->and(fn (): int => $buffer->requests(500_000))->toThrow(StorageUnavailableException::class, 'The Redis telemetry buffer returned an unexpected reply.')
        ->and([$client->received[0][2], $client->received[0][3]])->toBe([['dt:t:n:500000'], 1]);
});
