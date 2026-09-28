<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\OneCallPerAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Events\Dispatcher;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Redis\RedisManager;
use Predis\Client as PredisClient;
use Random\Engine\Mt19937;
use Random\Randomizer;

covers(RedisLimiterStore::class);

/*
| SPEC-027 AC-1, AC-2, AC-6 and AC-8 on Redis and Valkey: a request's attempts
| in one Lua script (ADR-0026), which must decide exactly like one call per
| attempt on the PHP reference. The FakeClock stands in for Redis TIME.
*/

/**
 * @return list<array{string, int}> the services, by host and port
 */
function batchServices(): array
{
    return [
        'Redis' => [(string) env('REDIS_HOST', '127.0.0.1'), (int) env('REDIS_PORT', 6379)],
        'Valkey' => [(string) env('VALKEY_HOST', '127.0.0.1'), (int) env('VALKEY_PORT', 6380)],
    ];
}

dataset('batch clients and services', function (): iterable {
    foreach (['phpredis', 'predis'] as $client) {
        foreach (batchServices() as $service => [$host, $port]) {
            yield "{$client}, {$service}" => [$client, $host, $port];
        }
    }
});

function batchSkipWithoutClient(string $client): void
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }
}

/**
 * A connection that reports every command it sends to $commands.
 *
 * @param  list<string>  $commands
 */
function batchListen(Connection $connection, array &$commands): void
{
    $events = new Dispatcher(new Container);
    $events->listen(CommandExecuted::class, function (CommandExecuted $event) use (&$commands): void {
        $commands[] = strtolower($event->command);
    });
    $connection->setEventDispatcher($events);
}

/**
 * The Redis driver, with every command it sends recorded in $commands.
 *
 * @param  list<string>  $commands
 */
function batchRedis(string $client, string $host, int $port, FakeClock $clock, array &$commands): RedisLimiterStore
{
    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0],
    ]);
    batchListen($manager->connection('default'), $commands);

    return new RedisLimiterStore($manager, 'default', new KeyBuilder('dt-batch-'.bin2hex(random_bytes(4)).':'), $clock);
}

function batchPlan(string $rule, int $max, bool $enforces = true, string $identity = 'A', int $window = 60): PlannedAttempt
{
    return new PlannedAttempt(new CounterKey($rule, hash('sha256', $identity)), $max, $window, $enforces);
}

/**
 * @return array{list<array{string, bool, int, int, int, bool}>, array<int, string>, ?string}
 */
function batchSummary(AttemptBatch $batch): array
{
    return [
        array_map(
            fn (Attempt $attempt, bool $released): array => [$attempt->key->ruleId, $attempt->allowed, $attempt->remaining, $attempt->retryAfter, $attempt->windowIndex, $released],
            $batch->attempts,
            $batch->released,
        ),
        array_map(fn (Throwable $failure): string => $failure->getMessage(), $batch->releaseFailures),
        $batch->failure?->getMessage(),
    ];
}

it('SPEC-027 AC-1: counts a request of 1, 3 or 10 attempts in one script call', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $commands = [];
    $clock = new FakeClock;
    $redis = batchRedis($client, $host, $port, $clock, $commands);
    // Redis may not have the script yet: the first call loads it.
    $redis->attemptAll([batchPlan('warm', 5)], false);
    $sent = [];

    foreach ([1, 3, 10] as $size) {
        $commands = [];
        $redis->attemptAll(array_map(fn (int $rule): PlannedAttempt => batchPlan((string) $rule, 100), range(1, $size)), false);
        $sent[$size] = $commands;
    }

    expect($sent)->toBe([1 => ['evalsha'], 3 => ['evalsha'], 10 => ['evalsha']]);
})->with('batch clients and services');

it('SPEC-027 AC-1: stops, releases and answers in the same script call', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $commands = [];
    $redis = batchRedis($client, $host, $port, new FakeClock, $commands);
    $redis->attemptAll([batchPlan('s', 1)], false);
    $commands = [];

    $batch = $redis->attemptAll([batchPlan('a', 100), batchPlan('b', 100), batchPlan('s', 1), batchPlan('t', 100)], false);

    expect($commands)->toBe(['evalsha'])
        ->and(batchSummary($batch)[0])->toBe([
            ['a', true, 99, 0, 30_000_000, true],
            ['b', true, 99, 0, 30_000_000, true],
            ['s', false, 0, 60, 30_000_000, false],
        ]);
})->with('batch clients and services');

it('SPEC-027 AC-2: decides exactly like one call per attempt on the PHP reference, on random plans', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $commands = [];
    $clock = new FakeClock;
    $redis = batchRedis($client, $host, $port, $clock, $commands);
    $reference = new OneCallPerAttempt(new CacheLimiterStore(new Repository(new ArrayStore), new KeyBuilder('dt:'), $clock));
    $random = new Randomizer(new Mt19937(20_260_928));
    $now = FakeClock::T0 * 1_000_000;
    $mismatches = [];
    $seen = ['enforcing rejections' => 0, 'dry-run rejections' => 0, 'released' => 0, 'release all' => 0];

    for ($step = 1; $step <= 300; $step++) {
        $gap = $random->getInt(1, 10);
        $now += match (true) {
            $gap <= 7 => $random->getInt(0, 200_000),
            $gap <= 9 => $random->getInt(0, 3_000_000),
            default => $random->getInt(0, 130_000_000),
        };
        $clock->setMicroseconds($now);

        // One client per request, and one counter per rule, in a random order.
        $identity = ['A', 'B'][$random->getInt(0, 1)];
        $rules = $random->shuffleArray(range(1, 10));
        $plan = array_map(
            fn (int $rule): PlannedAttempt => batchPlan((string) $rule, $random->getInt(1, 6), $random->getInt(1, 10) <= 7, $identity, [3, 7, 60][$rule % 3]),
            array_slice($rules, 0, $random->getInt(1, 10)),
        );
        $releaseAll = $random->getInt(1, 5) === 1;

        $actual = batchSummary($redis->attemptAll($plan, $releaseAll));
        $expected = batchSummary($reference->attemptAll($plan, $releaseAll));

        if ($actual !== $expected) {
            $mismatches[] = sprintf('step %d: Redis %s, reference %s', $step, json_encode($actual), json_encode($expected));
        }

        foreach ($expected[0] as $index => [, $allowed, , , , $released]) {
            $seen[$allowed ? 'released' : ($plan[$index]->enforces ? 'enforcing rejections' : 'dry-run rejections')] += $allowed ? (int) $released : 1;
        }

        $seen['release all'] += (int) $releaseAll;
    }

    expect($mismatches)->toHaveCount(0, implode("\n", array_slice($mismatches, 0, 3)))
        // The plans must exercise every path, or matching results would prove little.
        ->and($seen['enforcing rejections'])->toBeGreaterThan(50)
        ->and($seen['dry-run rejections'])->toBeGreaterThan(50)
        ->and($seen['released'])->toBeGreaterThan(100)
        ->and($seen['release all'])->toBeGreaterThan(30);
})->with('batch clients and services');

it('SPEC-027 AC-6: makes one call per attempt and per release on a Redis Cluster connection, with the same results', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);

    if ($client === 'phpredis') {
        $phpredis = new Redis;
        $phpredis->connect($host, $port, 2.0);
        $connection = new PhpRedisClusterConnection($phpredis);
    } else {
        $connection = new PredisClusterConnection(new PredisClient(['host' => $host, 'port' => $port]));
    }

    $commands = [];
    batchListen($connection, $commands);
    $factory = new readonly class($connection) implements RedisFactory
    {
        public function __construct(private Connection $connection) {}

        public function connection($name = null): Connection
        {
            return $this->connection;
        }
    };
    $redis = new RedisLimiterStore($factory, 'default', new KeyBuilder('dt-batch-'.bin2hex(random_bytes(4)).':'), new FakeClock);
    $redis->attempt(batchPlan('s', 1)->key, 1, 60);
    $redis->attempt(batchPlan('warm', 1)->key, 1, 60);
    $redis->release($redis->attempt(batchPlan('warm', 5)->key, 5, 60));
    $commands = [];

    $batch = $redis->attemptAll([batchPlan('a', 100), batchPlan('b', 100), batchPlan('s', 1), batchPlan('t', 100)], false);

    // Three attempts and two releases, each its own script call.
    expect($commands)->toBe(['evalsha', 'evalsha', 'evalsha', 'evalsha', 'evalsha'])
        ->and(batchSummary($batch)[0])->toBe([
            ['a', true, 99, 0, 30_000_000, true],
            ['b', true, 99, 0, 30_000_000, true],
            ['s', false, 0, 60, 30_000_000, false],
        ]);
})->with('batch clients and services');

it('SPEC-027 AC-9: returns the failure instead of throwing it when the script cannot run', function (string $client): void {
    batchSkipWithoutClient($client);
    // Nothing listens on port 1.
    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.5, 'read_timeout' => 0.5],
    ]);
    $redis = new RedisLimiterStore($manager, 'default', new KeyBuilder('dt:'), new FakeClock);

    $batch = $redis->attemptAll([batchPlan('a', 5), batchPlan('b', 5)], true);

    expect(batchSummary($batch))->toBe([[], [], 'The Redis limiter store could not count the request.'])
        ->and($batch->failure?->getPrevious())->not->toBeNull();
})->with(['phpredis', 'predis']);

it('SPEC-027 AC-8: decides in one round trip when a fail-closed rule\'s identity fails, whichever decision wins', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $commands = [];
    $redis = batchRedis($client, $host, $port, new FakeClock, $commands);
    $evaluator = new RuleEvaluator($redis);
    $rule = fn (string $id, int $max, string $onFailure = 'open'): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule([
        'id' => $id, 'max_attempts' => $max, 'on_failure' => $onFailure,
    ]));
    $tenantFails = new readonly class implements IdentityResolver
    {
        public function exemption(CompiledRule $rule): ?string
        {
            return null;
        }

        public function resolve(CompiledRule $rule): string
        {
            return $rule->id === 'c'
                ? throw IdentityUnavailableException::because('The identity for custom:tenant could not be resolved.')
                : hash('sha256', 'client');
        }
    };
    // Loads the script, and counts the client once on s, whose limit is 1.
    $redis->attemptAll([batchPlan('s', 1, identity: 'client')], false);
    $sent = [];
    $evaluations = [];

    foreach (['s', 'u', 'u'] as $first) {
        $commands = [];
        $evaluations[] = $evaluator->evaluate([$rule($first, 1), $rule('c', 100, 'closed')], $tenantFails);
        $sent[] = $commands;
    }

    [$rejected, $unavailable, $again] = $evaluations;

    // s rejects before c, so its 429 stands, and c's error is still reported. u is under its limit,
    // so c's 503 stands, and u's count is released in the same call: the next request is under it too.
    expect([$rejected->outcome, $unavailable->outcome, $again->outcome])->toBe([EvaluationOutcome::Rejected, EvaluationOutcome::Unavailable, EvaluationOutcome::Unavailable])
        ->and(array_map(fn (RuleFailure $failure): string => $failure->rule->id, $rejected->failures))->toBe(['c'])
        ->and(array_map(fn (RuleAttempt $counted): array => [$counted->rule->id, $counted->attempt->allowed, $counted->released], $unavailable->attempts))->toBe([['u', true, true]])
        ->and($sent)->toBe([['evalsha'], ['evalsha'], ['evalsha']]);
})->with('batch clients and services');

it('SPEC-027: takes the time from Redis TIME when there is no test clock', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $manager = new RedisManager(new Container, $client, [
        'default' => ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0],
    ]);
    $redis = new RedisLimiterStore($manager, 'default', new KeyBuilder('dt-batch-'.bin2hex(random_bytes(4)).':'));

    $before = (int) $manager->connection()->time()[0];
    $batch = $redis->attemptAll([batchPlan('a', 5), batchPlan('b', 5, window: 7)], false);
    $after = (int) $manager->connection()->time()[0];

    expect(array_map(fn (Attempt $attempt): bool => $attempt->allowed, $batch->attempts))->toBe([true, true])
        ->and($batch->attempts[0]->windowIndex)->toBeIn([intdiv($before, 60), intdiv($after, 60)])
        ->and($batch->attempts[1]->windowIndex)->toBeIn([intdiv($before, 7), intdiv($after, 7)]);
})->with('batch clients and services');

it('SPEC-027: applies Laravel\'s Redis key prefix to every key of the batch', function (string $client, string $host, int $port): void {
    batchSkipWithoutClient($client);
    $laravelPrefix = 'dt-app-'.bin2hex(random_bytes(4)).':';
    $keys = new KeyBuilder('dt-batch-'.bin2hex(random_bytes(4)).':');
    $server = ['host' => $host, 'port' => $port, 'database' => 0, 'timeout' => 2.0, 'read_timeout' => 2.0];
    $redis = new RedisLimiterStore(new RedisManager(new Container, $client, ['options' => ['prefix' => $laravelPrefix], 'default' => $server]), 'default', $keys, new FakeClock);
    $unprefixed = (new RedisManager(new Container, $client, ['default' => $server]))->connection();

    $redis->attemptAll([batchPlan('a', 5), batchPlan('b', 5)], false);

    $exists = fn (string $rule): array => [
        (int) $unprefixed->exists($laravelPrefix.$keys->counterBase(batchPlan($rule, 5)->key, 60)),
        (int) $unprefixed->exists($keys->counterBase(batchPlan($rule, 5)->key, 60)),
    ];

    expect([$exists('a'), $exists('b')])->toBe([[1, 0], [1, 0]]);
})->with('batch clients and services');
