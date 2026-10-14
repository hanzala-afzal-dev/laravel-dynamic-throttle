<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Auth\Middleware\Authenticate;
use Illuminate\Support\Carbon;

/*
| SPEC-016 AC-4 to AC-7: the checks of the config and the storage, which read
| the config only. SQLite; every check passes before each test changes one
| thing (Health::passing()). The clock stands at 2027-01-15 11:00:30 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-4: is ok with the defaults', function (): void {
    expect(Health::status('config'))->toBe('ok');
});

it('AC-4: errs when the counter driver cannot be built, with status\'s message, and skips the storage checks', function (array $config, string $message): void {
    config()->set($config);

    $checks = Health::checks();

    expect($checks['config'][0])->toBe('error')
        ->and($checks['config'][1])->toContain($message)
        ->and([$checks['store'][0], $checks['cache-clear'][0]])->toBe(['skipped', 'skipped']);
})->with([
    'an unknown driver' => [['dynamic-throttle.driver' => 'nope'], 'Unknown dynamic-throttle.driver "nope": use auto, redis or cache.'],
    'an undefined store' => [['dynamic-throttle.store' => 'missing'], 'Cache store [missing] is not defined.'],
    'an invalid prefix' => [['dynamic-throttle.prefix' => 'bad prefix!'], 'The key prefix must be at most 32 characters from A-Z, a-z, 0-9, ":", "_", "." and "-".'],
    'a Redis connection the app lacks' => [['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'nowhere'], 'Redis connection [nowhere] not configured.'],
]);

it('AC-4: warns that the middleware does nothing when the kill switch is off', function (): void {
    config()->set('dynamic-throttle.enabled', false);

    [$status, $message] = Health::check('config');

    expect($status)->toBe('warning')
        ->and($message)->toContain('dynamic-throttle.enabled is off');
});

it('AC-4: warns about a value the package replaces with its default, naming the key, the value and the value used', function (string $key, mixed $value, string $named): void {
    config()->set($key, $value);

    [$status, $message] = Health::check('config');

    expect($status)->toBe('warning')
        ->and($message)->toContain($named);
})->with([
    'a sample rate above 1' => ['dynamic-throttle.telemetry.sample_rate', 7, 'telemetry.sample_rate is 7, so the package uses 1.0'],
    'a sample rate written as a string' => ['dynamic-throttle.telemetry.sample_rate', '0.5', 'telemetry.sample_rate is "0.5", so the package uses 1.0'],
    'a cap of 0' => ['dynamic-throttle.matching.max_rules_per_request', 0, 'matching.max_rules_per_request is 0, so the package uses 5'],
    'a refresh below 0' => ['dynamic-throttle.manifest.refresh_seconds', -1, 'manifest.refresh_seconds is -1, so the package uses 2'],
    'a cooldown that is not a number' => ['dynamic-throttle.failure.cooldown_seconds', 'ten', 'failure.cooldown_seconds is "ten", so the package uses 10'],
    'a retention above the prune\'s maximum' => ['dynamic-throttle.telemetry.retention_hours', 2_000_000, 'telemetry.retention_hours is 2000000, so the package uses 1000000'],
    'an IPv6 prefix above 128' => ['dynamic-throttle.scopes.ip.ipv6_prefix', 129, 'scopes.ip.ipv6_prefix is 129, so the package uses 64'],
    'guards that are not a list' => ['dynamic-throttle.scopes.user.guards', 'web', 'scopes.user.guards is "web", so the package uses null'],
]);

it('AC-4: is ok with every value the package uses as written', function (): void {
    config()->set([
        'dynamic-throttle.telemetry.sample_rate' => 0.25,
        'dynamic-throttle.matching.max_rules_per_request' => 3,
        'dynamic-throttle.manifest.refresh_seconds' => 0,
        'dynamic-throttle.failure.cooldown_seconds' => 0,
        'dynamic-throttle.telemetry.retention_hours' => 1_000_000,
        'dynamic-throttle.scopes.ip.ipv6_prefix' => 128,
        'dynamic-throttle.scopes.user.guards' => ['sanctum', 'web'],
    ]);

    expect(Health::status('config'))->toBe('ok');
});

it('AC-4: warns about an auto_attach group the app does not define, by name', function (): void {
    config()->set('dynamic-throttle.middleware.auto_attach', ['api', 'apii']);

    [$status, $message] = Health::check('config');

    expect($status)->toBe('warning')
        ->and($message)->toContain('"apii"')
        ->not->toContain('"api"');
});

it('AC-4: warns about a middleware.after entry that is neither a middleware alias nor a class, by name', function (): void {
    config()->set('dynamic-throttle.middleware.after', ['jwt.auth', 'auth.basic', Authenticate::class]);

    [$status, $message] = Health::check('config');

    expect($status)->toBe('warning')
        ->and($message)->toContain('"jwt.auth"')
        ->not->toContain('auth.basic');
});

it('SPEC-018 AC-1: warns that the dashboard is not served when dashboard.path is empty, which would take the site\'s root', function (): void {
    config()->set('dynamic-throttle.dashboard.path', '/');

    expect(Health::check('config'))->toBe(['warning', 'dynamic-throttle.dashboard.path is empty, so the dashboard is not served: it would take the site\'s root.']);
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

it('AC-6: warns when the Redis driver counts in the Redis database of a redis cache store, naming the store', function (array $connection): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'database.redis.counters' => $connection,
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'dynamic-throttle.driver' => 'redis',
        'dynamic-throttle.redis_connection' => 'counters',
    ]);

    [$status, $message] = Health::check('cache-clear');

    expect($status)->toBe('warning')
        ->and($message)->toContain('cache:clear')->toContain('"redis"')->toContain('dedicated');
})->with([
    'the same connection\'s settings' => [['host' => '127.0.0.1', 'port' => 6379, 'database' => 1]],
    'the same database by url' => [['url' => 'redis://127.0.0.1:6379/1']],
    'the same database as a string' => [['host' => '127.0.0.1', 'port' => '6379', 'database' => '1']],
]);

it('AC-6: warns when the Redis driver uses a redis cache store\'s own connection', function (): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        'dynamic-throttle.driver' => 'redis',
        'dynamic-throttle.redis_connection' => 'cache',
    ]);

    expect(Health::check('cache-clear')[0])->toBe('warning');
});

it('AC-6: is ok when the Redis driver counts in a Redis database no cache store uses, or with the cache driver', function (array $config): void {
    config()->set([
        'database.redis.cache' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 1],
        'database.redis.counters' => ['host' => '127.0.0.1', 'port' => 6379, 'database' => 5],
        'cache.stores.redis' => ['driver' => 'redis', 'connection' => 'cache'],
        ...$config,
    ]);

    expect(Health::status('cache-clear'))->toBe('ok');
})->with([
    'another database' => [['dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'counters']],
    'another host' => [['database.redis.counters' => ['host' => '10.0.0.5', 'port' => 6379, 'database' => 1], 'dynamic-throttle.driver' => 'redis', 'dynamic-throttle.redis_connection' => 'counters']],
    'the cache driver on a redis store' => [['dynamic-throttle.driver' => 'cache', 'dynamic-throttle.store' => 'redis']],
]);

it('AC-7: warns without an app key, and is ok with one', function (): void {
    $withKey = Health::status('app-key');
    config()->set('app.key', '');
    [$status, $message] = Health::check('app-key');

    expect([$withKey, $status])->toBe(['ok', 'warning'])
        ->and($message)->toContain('APP_KEY');
});
