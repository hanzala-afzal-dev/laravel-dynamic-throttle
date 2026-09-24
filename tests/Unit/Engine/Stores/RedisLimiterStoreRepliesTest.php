<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
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
 * what it received. Mockery cannot mock a method named eval, a reserved word.
 */
function scriptedPhpRedis(mixed $evalsha, ?string $lastError = null, mixed $eval = null): Redis
{
    return new class($evalsha, $lastError, $eval) extends Redis
    {
        /** @var list<array{string, array<mixed>, int}> */
        public array $received = [];

        public function __construct(private readonly mixed $evalshaReply, private readonly ?string $lastError, private readonly mixed $evalReply)
        {
            // Never connects, so the parent constructor is not needed.
        }

        public function evalsha(string $sha1, array $args = [], int $num_keys = 0): mixed
        {
            $this->received[] = ['evalsha', $args, $num_keys];

            return $this->evalshaReply;
        }

        public function eval(string $script, array $args = [], int $num_keys = 0): mixed
        {
            $this->received[] = [str_contains($script, "redis.call('TIME')") ? 'eval' : 'eval of something else', $args, $num_keys];

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
        ->and($client->received)->toBe([['evalsha', stubbedScriptArguments(), 1], ['eval', stubbedScriptArguments(), 1]]);
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
