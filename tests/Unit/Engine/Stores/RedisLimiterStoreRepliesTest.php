<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisConnection;
use Mockery\Container;
use Predis\Client as PredisClient;
use Predis\Response\Error as PredisError;
use Predis\Response\ServerException as PredisServerException;

covers(RedisLimiterStore::class);

/*
| SPEC-003 AC-6 and AC-18: failure injection with stubbed clients (ADR-0016
| allows mocks for this). Against a real Redis, another test process can load
| the script at any moment, so only stubs make these paths certain. The stubs
| never check remaining: only the allowed flag and the window index.
*/

afterEach(function (): void {
    if (($container = Mockery::getContainer()) instanceof Container) {
        $this->addToAssertionCount($container->mockery_getExpectationCount());
    }

    Mockery::close();
});

function stubbedRedisStore(Connection $connection): RedisLimiterStore
{
    $factory = new readonly class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }
    };

    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);

    return new RedisLimiterStore($factory, 'default', new KeyBuilder('dt:'), $clock);
}

function stubbedAttempt(RedisLimiterStore $store): Attempt
{
    return $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60);
}

/**
 * The messages of an exception and of every exception before it.
 */
function exceptionChain(Throwable $exception): string
{
    $messages = [];

    for ($current = $exception; $current instanceof Throwable; $current = $current->getPrevious()) {
        $messages[] = $current->getMessage();
    }

    return implode(' <- ', $messages);
}

/**
 * A phpredis client that answers EVALSHA and EVAL with fixed replies and records
 * what it received, naming each script by its SHA-1. Mockery cannot mock a
 * method named eval, a reserved word.
 */
function scriptedPhpRedis(mixed $evalsha, ?string $lastError = null, mixed $eval = null): Redis
{
    return new class($evalsha, $lastError, $eval) extends Redis
    {
        /** @var list<array{string, string, array<mixed>, int}> */
        public array $received = [];

        public function __construct(private readonly mixed $evalshaReply, private readonly ?string $lastError, private readonly mixed $evalReply)
        {
            // Never connects, so the parent constructor is not needed.
        }

        public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
        {
            $this->received[] = ['evalsha', $sha1, $args, $num_keys];

            return $this->evalshaReply;
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
 * What the script receives for stubbedAttempt(): KEYS[1], then max, window and the test time.
 *
 * @return list<string>
 */
function stubbedScriptArguments(): array
{
    return ['dt:c:1:60:'.hash('sha256', 'A'), '5', '60', (string) ((FakeClock::T0 + 10) * 1_000_000)];
}

/** A reply the script could send for an allowed attempt at T0+10 with window 60. */
const STUBBED_ALLOWED_REPLY = [1, 4, 0, 30_000_000];

it('AC-6: falls back to EVAL once when phpredis reports NOSCRIPT', function (): void {
    $client = scriptedPhpRedis(evalsha: false, lastError: 'NOSCRIPT No matching script. Please use EVAL.', eval: STUBBED_ALLOWED_REPLY);

    $attempt = stubbedAttempt(stubbedRedisStore(new PhpRedisConnection($client)));

    expect([$attempt->allowed, $attempt->windowIndex])->toBe([true, 30_000_000])
        ->and($client->received)->toBe([
            ['evalsha', RedisLimiterStore::scriptSha(), stubbedScriptArguments(), 1],
            ['eval', RedisLimiterStore::scriptSha(), stubbedScriptArguments(), 1],
        ]);
});

it('AC-6: falls back to EVAL once when predis reports NOSCRIPT', function (Throwable|PredisError $noscript): void {
    $client = Mockery::mock(PredisClient::class);
    $client->shouldReceive('evalsha')->once()->andReturnUsing(function () use ($noscript): PredisError {
        if ($noscript instanceof Throwable) {
            throw $noscript;
        }

        return $noscript;
    });
    $client->shouldReceive('eval')->once()
        ->withArgs(fn (string $script, int $keys): bool => str_contains($script, "redis.call('TIME')") && $keys === 1)
        ->andReturn(STUBBED_ALLOWED_REPLY);

    $attempt = stubbedAttempt(stubbedRedisStore(new PredisConnection($client)));

    expect([$attempt->allowed, $attempt->windowIndex])->toBe([true, 30_000_000]);
})->with([
    'with exceptions (a ServerException)' => [new PredisServerException('NOSCRIPT No matching script. Please use EVAL.')],
    'with exceptions off (an error reply)' => [new PredisError('NOSCRIPT No matching script. Please use EVAL.')],
]);

it('AC-6: never falls back to EVAL for another phpredis error, and names it', function (): void {
    $client = scriptedPhpRedis(evalsha: false, lastError: 'ERR Error running script, NOSCRIPT is not the cause');

    expect(fn (): Attempt => stubbedAttempt(stubbedRedisStore(new PhpRedisConnection($client))))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect(exceptionChain($exception))->toContain('Redis rejected the limiter script: ERR Error running script');
        })
        ->and(array_column($client->received, 0))->toBe(['evalsha']);
});

it('AC-6: never falls back to EVAL for another predis error, and names it', function (Throwable|PredisError $error, string $named): void {
    $client = Mockery::mock(PredisClient::class);
    $client->shouldReceive('evalsha')->once()->andReturnUsing(function () use ($error): PredisError {
        if ($error instanceof Throwable) {
            throw $error;
        }

        return $error;
    });
    $client->shouldReceive('eval')->never();

    expect(fn (): Attempt => stubbedAttempt(stubbedRedisStore(new PredisConnection($client))))
        ->toThrow(function (StorageUnavailableException $exception) use ($named): void {
            expect(exceptionChain($exception))->toContain($named);
        });
})->with([
    // The ServerException itself is the previous exception.
    'with exceptions (a ServerException)' => [new PredisServerException('ERR Error running script, NOSCRIPT is not the cause'), 'ERR Error running script'],
    'with exceptions off (an error reply)' => [new PredisError('ERR Error running script, NOSCRIPT is not the cause'), 'Redis rejected the limiter script: ERR Error running script'],
]);

it('AC-18: reports a reply that is not four integers as unavailable', function (mixed $reply): void {
    $client = scriptedPhpRedis(evalsha: $reply);

    expect(fn (): Attempt => stubbedAttempt(stubbedRedisStore(new PhpRedisConnection($client))))
        ->toThrow(StorageUnavailableException::class, 'unexpected reply');
})->with([
    'three integers' => [[1, 4, 0]],
    'a string among them' => [[1, '4', 0, 30_000_000]],
    'five integers' => [[1, 4, 0, 30_000_000, 7]],
    'not a list' => [[1 => 1, 2 => 4, 3 => 0, 4 => 30_000_000]],
    'a status reply' => ['OK'],
]);

it('AC-18: reports a connection that is neither phpredis nor predis as unavailable', function (): void {
    $connection = new class extends Connection
    {
        public function __construct()
        {
            $this->client = new stdClass;
        }

        public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}
    };

    expect(fn (): Attempt => stubbedAttempt(stubbedRedisStore($connection)))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->getPrevious()?->getMessage())->toBe('Unsupported Redis connection '.Connection::class.'@anonymous; use phpredis or predis.');
        });
});

it('AC-18: reports a phpredis connection around another client as unavailable', function (): void {
    // Answers EVALSHA like phpredis does on an error, but is no phpredis client.
    $client = new class
    {
        public function evalsha(string $sha1, array $args = [], int $num_keys = 0): bool
        {
            return false;
        }
    };

    expect(fn (): Attempt => stubbedAttempt(stubbedRedisStore(new PhpRedisConnection($client))))
        ->toThrow(function (StorageUnavailableException $exception): void {
            expect($exception->getPrevious())->toBeInstanceOf(InvalidArgumentException::class)
                ->and($exception->getPrevious()?->getMessage())->toBe('Unsupported phpredis client class@anonymous.');
        });
});

/*
| SPEC-004: release() runs its own script through the same EVALSHA/EVAL runner.
*/

function stubbedAllowedAttempt(): Attempt
{
    return new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, true, 4, 0);
}

it('SPEC-004 AC-7: releasing a rejected attempt makes no call to Redis', function (): void {
    $factory = new class implements RedisFactory
    {
        public int $connections = 0;

        public function connection($name = null): Connection
        {
            $this->connections++;

            throw new RuntimeException('Redis was called.');
        }
    };
    $store = new RedisLimiterStore($factory, 'default', new KeyBuilder('dt:'), new FakeClock);

    $store->release(new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, false, 0, 62));

    expect($factory->connections)->toBe(0);
});

it('SPEC-004 AC-9: falls back to EVAL of the release script once when phpredis reports NOSCRIPT', function (): void {
    $client = scriptedPhpRedis(evalsha: false, lastError: 'NOSCRIPT No matching script. Please use EVAL.', eval: 1);

    stubbedRedisStore(new PhpRedisConnection($client))->release(stubbedAllowedAttempt());

    // KEYS[1] is the counter's hash, ARGV[1] the window the attempt was counted in.
    $arguments = ['dt:c:1:60:'.hash('sha256', 'A'), '30000000'];

    expect($client->received)->toBe([
        ['evalsha', RedisLimiterStore::releaseScriptSha(), $arguments, 1],
        ['eval', RedisLimiterStore::releaseScriptSha(), $arguments, 1],
    ]);
});

it('SPEC-004 AC-12: reports a release reply that is not an integer as unavailable', function (mixed $reply): void {
    $client = scriptedPhpRedis(evalsha: $reply);

    expect(fn () => stubbedRedisStore(new PhpRedisConnection($client))->release(stubbedAllowedAttempt()))
        ->toThrow(StorageUnavailableException::class, 'The Redis limiter store returned an unexpected reply.');
})->with([
    'a status reply' => ['OK'],
    'a list' => [[1]],
    'a numeric string' => ['1'],
]);

/*
| SPEC-027: the batch script, through the same EVALSHA/EVAL runner. A failure
| is part of the result, never an exception.
*/

/**
 * A batch of one enforcing attempt, 5 per 60 s, on the stubbed store.
 */
function stubbedBatch(Redis $client): AttemptBatch
{
    return stubbedRedisStore(new PhpRedisConnection($client))
        ->attemptAll([new PlannedAttempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, true)], false);
}

it('SPEC-027: sends every counter as a key, then the time, whether to release all, and each attempt\'s limit, window and mode', function (): void {
    $client = scriptedPhpRedis(evalsha: [1, 4, 0, 30_000_000, 1, 1, 2, 0, 180_000_001, 1]);

    $batch = stubbedRedisStore(new PhpRedisConnection($client))->attemptAll([
        new PlannedAttempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, true),
        new PlannedAttempt(new CounterKey('2', hash('sha256', 'A')), 3, 10, false),
    ], true);

    expect($client->received)->toBe([[
        'evalsha',
        RedisLimiterStore::batchScriptSha(),
        [
            'dt:c:1:60:'.hash('sha256', 'A'), 'dt:c:2:10:'.hash('sha256', 'A'),
            (string) ((FakeClock::T0 + 10) * 1_000_000), '1',
            '5', '60', '1',
            '3', '10', '0',
        ],
        2,
    ]])
        ->and(array_map(fn (Attempt $attempt, bool $released): array => [$attempt->key->ruleId, $attempt->remaining, $attempt->windowIndex, $released], $batch->attempts, $batch->released))
        ->toBe([['1', 1, 30_000_000, true], ['2', 1, 180_000_001, true]]);
});

it('SPEC-027: falls back to EVAL of the batch script once when phpredis reports NOSCRIPT', function (): void {
    $client = scriptedPhpRedis(evalsha: false, lastError: 'NOSCRIPT No matching script. Please use EVAL.', eval: [1, 4, 0, 30_000_000, 0]);

    $batch = stubbedBatch($client);

    expect(array_map(fn (array $call): array => [$call[0], $call[1]], $client->received))->toBe([
        ['evalsha', RedisLimiterStore::batchScriptSha()],
        ['eval', RedisLimiterStore::batchScriptSha()],
    ])
        ->and([$batch->attempts[0]->allowed, $batch->failure])->toBe([true, null]);
});

it('SPEC-027: returns a script error as the batch\'s failure, and names it', function (): void {
    $batch = stubbedBatch(scriptedPhpRedis(evalsha: false, lastError: 'ERR Error running script'));

    expect($batch->attempts)->toBe([])
        ->and(exceptionChain($batch->failure ?? new LogicException('No failure.')))
        ->toBe('The Redis limiter store could not count the request. <- Redis rejected the limiter script: ERR Error running script');
});

it('SPEC-027: returns a reply that is not five integers per attempt made, for at most the attempts planned, as a failure', function (mixed $reply): void {
    $batch = stubbedBatch(scriptedPhpRedis(evalsha: $reply));

    expect([$batch->attempts, $batch->failure?->getMessage()])->toBe([[], 'The Redis limiter store returned an unexpected reply.']);
})->with([
    'no attempt' => [[]],
    'four integers' => [[1, 4, 0, 30_000_000]],
    'six integers' => [[1, 4, 0, 30_000_000, 0, 1]],
    'two attempts for a plan of one' => [[1, 4, 0, 30_000_000, 0, 1, 3, 0, 30_000_000, 0]],
    'a string among them' => [[1, 4, 0, '30000000', 0]],
    'not a list' => [[1 => 1, 2 => 4, 3 => 0, 4 => 30_000_000, 5 => 0]],
    'a status reply' => ['OK'],
]);

it('SPEC-027: makes no call to Redis for an empty plan', function (): void {
    $factory = new class implements RedisFactory
    {
        public int $connections = 0;

        public function connection($name = null): Connection
        {
            $this->connections++;

            throw new RuntimeException('Redis was called.');
        }
    };
    $store = new RedisLimiterStore($factory, 'default', new KeyBuilder('dt:'), new FakeClock);

    $batch = $store->attemptAll([], true);

    expect([$batch->attempts, $batch->released, $batch->failure, $factory->connections])->toBe([[], [], null, 0]);
});

it('SPEC-027: returns a reply that stops before the plan\'s end without an enforcing rejection as a failure', function (bool $firstEnforces, array $reply): void {
    $batch = stubbedRedisStore(new PhpRedisConnection(scriptedPhpRedis(evalsha: $reply)))->attemptAll([
        new PlannedAttempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, $firstEnforces),
        new PlannedAttempt(new CounterKey('2', hash('sha256', 'A')), 5, 60, true),
    ], false);

    expect([$batch->attempts, $batch->failure?->getMessage()])->toBe([[], 'The Redis limiter store returned an unexpected reply.']);
})->with([
    'after an allowed attempt' => [true, [1, 4, 0, 30_000_000, 0]],
    'after a dry-run rejection' => [false, [0, 0, 12, 30_000_000, 0]],
]);

it('SPEC-027: accepts a reply that stops at an enforcing rejection', function (): void {
    $batch = stubbedRedisStore(new PhpRedisConnection(scriptedPhpRedis(evalsha: [0, 0, 12, 30_000_000, 0])))->attemptAll([
        new PlannedAttempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, true),
        new PlannedAttempt(new CounterKey('2', hash('sha256', 'A')), 5, 60, true),
    ], false);

    expect(array_map(fn (Attempt $attempt): array => [$attempt->key->ruleId, $attempt->allowed, $attempt->retryAfter], $batch->attempts))->toBe([['1', false, 12]])
        ->and($batch->failure)->toBeNull();
});
