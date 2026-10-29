<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| Issue #99: the redis-timeouts check. A Redis that hangs holds each call for
| the connection's timeout before the limits fail open, or a few times that
| while the client reconnects and retries. Without a timeout, phpredis waits
| PHP's default_socket_timeout, 60 seconds by default, and Predis 5 seconds to
| connect. The check reads the config only. Every check passes before each
| test changes one thing (Health::passing()), and default_socket_timeout is 60.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
    $this->socketTimeout = ini_get('default_socket_timeout');
    ini_set('default_socket_timeout', '60');
});

afterEach(function (): void {
    ini_set('default_socket_timeout', (string) $this->socketTimeout);
    ManifestRepository::flushState();
});

/** Timeouts a dedicated connection should have, for both clients. */
const SHORT_TIMEOUTS = ['timeout' => 0.25, 'read_timeout' => 0.25, 'read_write_timeout' => 0.25];

/**
 * The Redis driver on a connection named "counters", with the manifest where
 * Health::passing() put it, in a store that is not Redis.
 *
 * @param  array<string, mixed>  $connection
 */
function countersOn(array $connection, string $client = 'phpredis'): void
{
    config()->set([
        'database.redis.client' => $client,
        'database.redis.counters' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 3, ...$connection],
        'dynamic-throttle.driver' => 'redis',
        'dynamic-throttle.redis_connection' => 'counters',
    ]);
}

it('is listed after cache-clear', function (): void {
    expect(array_keys(Health::checks()))->toBe(Health::CHECKS)
        ->and(array_slice(Health::CHECKS, 2, 2))->toBe(['cache-clear', 'redis-timeouts']);
});

it('is skipped when neither the counters nor the manifest are on Redis', function (): void {
    [$status, $message] = Health::check('redis-timeouts');

    expect($status)->toBe('skipped')
        ->and($message)->toBe('Neither the counters nor the manifest are on Redis.');
});

it('is skipped when the config is invalid, which the config check reports', function (): void {
    config()->set('dynamic-throttle.driver', 'nope');

    expect(Health::check('redis-timeouts'))->toBe(['skipped', 'The config is invalid: see the config check.']);
});

it('warns about a phpredis connection without timeouts, which waits default_socket_timeout, and says what to set', function (): void {
    countersOn([]);

    [$status, $message] = Health::check('redis-timeouts');

    expect($status)->toBe('warning')
        ->and($message)->toBe('A Redis that hangs holds a request 60 seconds or more before its limits fail open: "counters", for the counters, waits 60 seconds to connect and 60 seconds for each reply. '
            .'Set "timeout" and "read_timeout" ("read_write_timeout" with Predis) to about 0.25 seconds on "counters", in config/database.php: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/storage-and-runtimes#timeouts.');
});

it('is ok with short timeouts, names the longest, and says retries can repeat it', function (array $connection): void {
    countersOn($connection);

    expect(Health::check('redis-timeouts'))->toBe(['ok', 'The Redis connections requests use time out within 0.5 seconds: "counters", for the counters. A call can wait a few times that while the client reconnects and retries: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/storage-and-runtimes#timeouts.']);
})->with([
    'as numbers' => [['timeout' => 0.5, 'read_timeout' => 0.25]],
    'as strings, from the environment' => [['timeout' => '0.5', 'read_timeout' => '0.25']],
    'in the url' => [['url' => 'redis://127.0.0.1:6379/3?timeout=0.5&read_timeout=0.25']],
]);

it('warns about a timeout above one second, and only about that one', function (): void {
    countersOn(['timeout' => 0.25, 'read_timeout' => 5]);

    [$status, $message] = Health::check('redis-timeouts');

    expect($status)->toBe('warning')
        ->and($message)->toStartWith('A Redis that hangs holds a request 5 seconds or more before its limits fail open: "counters", for the counters, waits 5 seconds for each reply. ');
});

it('warns about Predis\'s defaults: 5 seconds to connect, and default_socket_timeout for each reply', function (): void {
    countersOn([], 'predis');

    expect(Health::message('redis-timeouts'))->toStartWith('A Redis that hangs holds a request 60 seconds or more before its limits fail open: "counters", for the counters, waits 5 seconds to connect and 60 seconds for each reply. ');
});

it('warns that a Predis read_write_timeout of 0 never gives up', function (): void {
    countersOn(['timeout' => 0.25, 'read_write_timeout' => 0], 'predis');

    expect(Health::message('redis-timeouts'))->toStartWith('A Redis that hangs holds a request forever before its limits fail open: "counters", for the counters, waits forever for each reply. ');
});

it('is ok on Predis with short timeouts, and ignores phpredis\'s read_timeout', function (): void {
    countersOn(['timeout' => 0.25, 'read_timeout' => 30, 'read_write_timeout' => 0.25], 'predis');

    expect(Health::status('redis-timeouts'))->toBe('ok');
});

it('checks the connection of the redis cache store that holds the manifest, and of the counters, each once', function (): void {
    countersOn(SHORT_TIMEOUTS);
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'dynamic-throttle.store' => 'redis',
    ]);

    [$status, $message] = Health::check('redis-timeouts');

    expect($status)->toBe('warning')
        ->and($message)->toContain(': "cache", for the manifest, waits 60 seconds to connect and 60 seconds for each reply. ')
        ->not->toContain('"counters"');
});

it('issue #151: names the connections that wait too long in its advice, the manifest\'s too, not only the package\'s own', function (): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'dynamic-throttle.store' => 'redis',
    ]);

    countersOn(SHORT_TIMEOUTS);
    expect(Health::message('redis-timeouts'))->toEndWith(' on "cache", in config/database.php: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/storage-and-runtimes#timeouts.');

    countersOn([]);
    expect(Health::message('redis-timeouts'))->toEndWith(' on "counters" and "cache", in config/database.php: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/storage-and-runtimes#timeouts.');
});

it('names both uses of one connection, as with the auto driver on a redis store', function (): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1, ...SHORT_TIMEOUTS],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'dynamic-throttle.driver' => 'auto',
        'dynamic-throttle.store' => 'redis',
    ]);

    expect(Health::check('redis-timeouts'))->toBe(['ok', 'The Redis connections requests use time out within 0.25 seconds: "cache", for the counters and the manifest. A call can wait a few times that while the client reconnects and retries: see https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/guide/storage-and-runtimes#timeouts.']);
});

it('checks the cache driver on a redis store, whose connection defaults to "default"', function (): void {
    config()->set([
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 0],
        'cache.stores.redis' => ['driver' => 'redis'],
        'dynamic-throttle.driver' => 'cache',
        'dynamic-throttle.store' => 'redis',
    ]);

    expect(Health::message('redis-timeouts'))->toContain(': "default", for the counters and the manifest, waits');
});

it('reads a phpredis cluster\'s timeouts from its options', function (array $options, string $status): void {
    config()->set([
        'database.redis.client' => 'phpredis',
        'database.redis.clusters.counters' => [['host' => '10.0.0.1', 'port' => 6379], ['host' => '10.0.0.2', 'port' => 6379]],
        'dynamic-throttle.driver' => 'redis',
        'dynamic-throttle.redis_connection' => 'counters',
        ...$options,
    ]);

    expect(Health::status('redis-timeouts'))->toBe($status);
})->with([
    'none' => [[], 'warning'],
    'the clusters\' options' => [['database.redis.clusters.options' => ['timeout' => 0.25, 'read_timeout' => 0.25]], 'ok'],
    'the connections\' options' => [['database.redis.options' => ['timeout' => 0.25, 'read_timeout' => 0.25]], 'ok'],
    'its own options' => [['database.redis.clusters.counters.options' => ['timeout' => 0.25, 'read_timeout' => 0.25]], 'ok'],
]);

it('reads a Predis cluster\'s timeouts from each node, and warns about the worst', function (): void {
    config()->set([
        'database.redis.client' => 'predis',
        'database.redis.clusters.counters' => [
            ['host' => '10.0.0.1', 'port' => 6379, ...SHORT_TIMEOUTS],
            ['host' => '10.0.0.2', 'port' => 6379, 'timeout' => 0.25, 'read_write_timeout' => 2],
        ],
        'dynamic-throttle.driver' => 'redis',
        'dynamic-throttle.redis_connection' => 'counters',
    ]);

    expect(Health::message('redis-timeouts'))->toStartWith('A Redis that hangs holds a request 2 seconds or more before its limits fail open: "counters", for the counters, waits 2 seconds for each reply. ');
});

it('reads a Predis connection\'s own parameters among the client\'s, as Laravel does', function (array $parameters, string $status, string $named): void {
    countersOn([], 'predis');
    config()->set('database.redis.options.parameters', $parameters);

    [$actual, $message] = Health::check('redis-timeouts');

    expect($actual)->toBe($status)
        ->and($message)->toContain($named);
})->with([
    'its own, under its name' => [['counters' => ['timeout' => 0.25, 'read_write_timeout' => 0.25]], 'ok', 'within 0.25 seconds'],
    // Laravel takes the connection's entry whole, so the client's are not merged into it.
    'its own over the client\'s' => [['timeout' => 0.25, 'read_write_timeout' => 0.25, 'counters' => ['read_write_timeout' => 30]], 'warning', 'waits 5 seconds to connect and 30 seconds for each reply'],
    'the client\'s, without its own' => [['timeout' => 0.25, 'read_write_timeout' => 0.25], 'ok', 'within 0.25 seconds'],
]);

it('checks the first store of a failover cache store, which every call tries first', function (): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'cache.stores.local' => ['driver' => 'array'],
        'cache.stores.failover' => ['driver' => 'failover', 'stores' => ['redis', 'local']],
        'dynamic-throttle.driver' => 'cache',
        'dynamic-throttle.store' => 'failover',
    ]);

    expect(Health::message('redis-timeouts'))->toContain(': "cache", for the counters and the manifest, waits 60 seconds');
});
