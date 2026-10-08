<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-016 AC-5 and AC-7: the checks of the storage and the app key, which
| read the config only; AC-4 and AC-6 come with their checks. SQLite; every
| check passes before each test changes one thing (Health::passing()). The
| clock stands at 2027-01-15 11:00:30 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-5: says what the counter store guarantees', function (array $config, string $status, string $named): void {
    foreach (['memcached', 'dynamodb', 'octane', 'mongodb'] as $driver) {
        Health::inMemory($driver);
    }

    config()->set($config);

    [$checked, $message] = Health::check('store');

    expect($checked)->toBe($status)
        ->and($message)->toContain($named);
})->with([
    'the Redis driver' => [['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'default'], 'ok', 'Redis driver'],
    'the cache driver on a redis store' => [['dynamic-throttle.driver' => 'cache', 'cache.stores.health' => ['driver' => 'redis', 'connection' => 'default']], 'ok', 'redis'],
    'a memcached store' => [['cache.stores.health' => ['driver' => 'memcached']], 'ok', 'memcached'],
    'a dynamodb store' => [['cache.stores.health' => ['driver' => 'dynamodb']], 'ok', 'dynamodb'],
    'a database store' => [['cache.stores.health' => ['driver' => 'database', 'connection' => 'mysql', 'table' => 'cache']], 'warning', 'database writes'],
    'a file store' => [['cache.stores.health' => ['driver' => 'file', 'path' => sys_get_temp_dir()]], 'warning', 'per server'],
    'an apc store' => [['cache.stores.health' => ['driver' => 'apc']], 'warning', 'per server'],
    'an octane store' => [['cache.stores.health' => ['driver' => 'octane']], 'warning', 'per server'],
    'an array store' => [['cache.stores.health' => ['driver' => 'array']], 'warning', 'tests only'],
    'a null store' => [['cache.stores.health' => ['driver' => 'null']], 'error', 'counts nothing'],
    'a store of another driver' => [['cache.stores.health' => ['driver' => 'mongodb']], 'ok', 'tests do not cover'],
]);

it('AC-5: names the lost counts of the file and apc stores, and not of the octane store', function (string $driver, bool $lost): void {
    Health::inMemory('octane');
    config()->set('cache.stores.health', ['driver' => $driver, 'path' => sys_get_temp_dir()]);

    expect(str_contains(Health::message('store'), 'loses counts'))->toBe($lost);
})->with([
    'file' => ['file', true],
    'apc' => ['apc', true],
    'octane' => ['octane', false],
]);

it('AC-5: names the failures under contention of a database store on SQLite only', function (string $connection, bool $named): void {
    config()->set('cache.stores.health', ['driver' => 'database', 'connection' => $connection, 'table' => 'cache']);

    expect(str_contains(Health::message('store'), 'contention'))->toBe($named);
})->with([
    'SQLite' => ['testing', true],
    'MySQL' => ['mysql', false],
]);

it('AC-7: warns without an app key, and is ok with one', function (): void {
    $withKey = Health::status('app-key');
    config()->set('app.key', '');
    [$status, $message] = Health::check('app-key');

    expect([$withKey, $status])->toBe(['ok', 'warning'])
        ->and($message)->toContain('APP_KEY');
});
