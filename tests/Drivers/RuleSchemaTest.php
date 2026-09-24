<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-005 AC-11 and AC-14 on the docker compose MySQL and PostgreSQL. Each
| test gets a random table prefix, so parallel runs never share a table.
*/

it('AC-11 and AC-14: migrates up and down, and keeps every type', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.rules-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => 'p'.bin2hex(random_bytes(4)).'_',
    ]);
    config()->set('dynamic-throttle.database.connection', "rules-{$driver}");

    RuleSchema::migrate();

    try {
        $created = Schema::connection("rules-{$driver}")->hasTable('dynamic_throttle_rules');
        [$rule, $override] = RuleSchema::roundTrip();
    } finally {
        RuleSchema::rollback();
    }

    expect($created)->toBeTrue()
        ->and(Schema::connection("rules-{$driver}")->hasTable('dynamic_throttle_rules'))->toBeFalse()
        ->and([$rule->method, $rule->scope, $rule->mode, $rule->on_failure, $rule->on_missing_identity])
        ->toBe([RequestMethod::Post, Scope::Header, RuleMode::DryRun, FailurePolicy::Closed, MissingIdentity::Skip])
        ->and([$rule->max_attempts, $rule->window_seconds, $rule->priority])->toBe([60, 60, 5])
        ->and($rule->enabled)->toBeFalse()
        ->and($rule->response_override)->toBe($override)
        ->and($rule->scope_parameter)->toBe('X-Api-Key');
})->with([
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);
