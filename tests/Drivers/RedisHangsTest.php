<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\HungRedis;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| Issue #99 and the runbook's "Redis hangs" (docs/runbook.md#redis-hangs): a
| Redis that accepts connections and never answers costs a request one timeout
| of the connection, then fails open, or 503 on a fail-closed rule. A failed
| call is not followed by another, except where the client reconnects or
| retries: Laravel's phpredis connection retries a read once (cacheReadWaits()),
| and reconnects after a failed call, which selects the database again. The
| connection has the timeouts the docs recommend, 0.3 seconds here. It needs no
| service, but runs with the drivers, which have both clients.
*/

const HUNG_TIMEOUT = 0.3;

beforeEach(function (): void {
    Throttling::prepare();
    Exceptions::fake();
    $this->hung = HungRedis::listen();
    Throttling::rule(['pattern' => 'api/orders', 'max_attempts' => 5]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 5, 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/orders');
    Throttling::route('api/login');
});

afterEach(function (): void {
    ManifestRepository::flushState();
    app('redis')->purge('hung');
    $this->hung->close();
});

/**
 * The given Redis client, with the "hung" connection.
 */
function hungClient(string $client): void
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    app()->forgetInstance('redis');
    config()->set('database.redis.client', $client);
    config()->set('database.redis.hung', test()->hung->connection(HUNG_TIMEOUT));
}

/**
 * The response, and how long the request took, in seconds, its terminate() included.
 *
 * @return array{TestResponse<Response>, float}
 */
function timedGet(string $uri): array
{
    $start = hrtime(true);
    $response = test()->get($uri);

    return [$response, (hrtime(true) - $start) / 1e9];
}

/**
 * How many timeouts a cache read waits on the hung Redis. Laravel's phpredis
 * connection retries a read such as GET once after a lost connection, and counts
 * a timeout as one, in the Laravel versions that list RETRYABLE_COMMANDS.
 */
function cacheReadWaits(string $client): int
{
    return $client === 'phpredis' && (new ReflectionClass(PhpRedisConnection::class))->hasConstant('RETRYABLE_COMMANDS') ? 2 : 1;
}

dataset('clients', ['phpredis', 'predis']);

it('lets a fail-open rule\'s request through after one timeout, and the next ones at once while the cooldown runs', function (string $client): void {
    // The counters on the hung connection, and the manifest in a cache that works.
    hungClient($client);
    config()->set(['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'hung']);
    app()->forgetScopedInstances();

    [$first, $firstTook] = timedGet('api/orders');
    [$next, $nextTook] = timedGet('api/orders');

    expect([$first->status(), Throttling::headers($first), $next->status()])->toBe([200, [], 200])
        ->and($firstTook)->toBeGreaterThanOrEqual(HUNG_TIMEOUT)->toBeLessThan(2 * HUNG_TIMEOUT)
        ->and($nextTook)->toBeLessThan(HUNG_TIMEOUT);
})->with('clients');

it('answers a fail-closed rule\'s request with 503 after one timeout', function (string $client): void {
    hungClient($client);
    config()->set(['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'hung']);
    app()->forgetScopedInstances();

    [$response, $took] = timedGet('api/login');

    expect($response->status())->toBe(503)
        ->and($took)->toBeGreaterThanOrEqual(HUNG_TIMEOUT)->toBeLessThan(2 * HUNG_TIMEOUT);
})->with('clients');

it('lets a request through after one read of the manifest when it is on the hung Redis too, in a process that starts during the hang', function (string $client): void {
    hungClient($client);
    config()->set('cache.stores.hung', ['driver' => 'redis', 'connection' => 'hung']);
    config()->set('dynamic-throttle.store', 'hung');
    ManifestRepository::flushState();
    app()->forgetScopedInstances();

    [$response, $took] = timedGet('api/login');

    $waits = cacheReadWaits($client);

    // No manifest, so no rule is known, not even the fail-closed one (ADR-0010 point 3).
    // Without the rebuild's lock after the failed read, which waited as long again.
    expect($response->status())->toBe(200)
        ->and($took)->toBeGreaterThanOrEqual($waits * HUNG_TIMEOUT)->toBeLessThan(($waits + 1) * HUNG_TIMEOUT);
})->with('clients');

it('waits twice the timeout with phpredis when the connection selects a database and Redis hangs once it is open, once with Predis', function (string $client): void {
    $this->hung->close();
    $this->hung = HungRedis::afterFirstReply();
    hungClient($client);
    config()->set('database.redis.hung.database', 2);
    config()->set(['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'hung']);
    app()->forgetScopedInstances();

    [$response, $took] = timedGet('api/orders');
    // phpredis reconnects after the failed call, and its SELECT waits too.
    $waits = $client === 'phpredis' && method_exists(PhpRedisConnection::class, 'rebuildClient') ? 2 : 1;

    expect($response->status())->toBe(200)
        ->and($took)->toBeGreaterThanOrEqual($waits * HUNG_TIMEOUT)->toBeLessThan(($waits + 1) * HUNG_TIMEOUT);
})->with('clients');
