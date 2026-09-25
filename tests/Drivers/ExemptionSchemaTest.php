<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-009 AC-3 on the docker compose MySQL and PostgreSQL. Each test gets a
| random table prefix, so parallel runs never share a table.
*/

it('AC-3: migrates up and down, keeps every type, and deletes a rule\'s exemptions with it', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.exemptions-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => 'p'.bin2hex(random_bytes(4)).'_',
    ]);
    config()->set('dynamic-throttle.database.connection', "exemptions-{$driver}");

    RuleSchema::migrate();

    try {
        $created = Schema::connection("exemptions-{$driver}")->hasTable('dynamic_throttle_exemptions');
        $deleted = Exemptions::rule();
        $kept = Exemptions::rule();
        Exemption::forIp('10.0.0.0/8')->forRule($deleted)->save();
        $other = Exemption::forCustom('tenant', 'acme')->forRule($kept)->until(Carbon::createFromTimestamp(FakeClock::T0 + 60))->because('A contract');
        $other->save();
        $global = Exemption::forIp('2001:db8::/32');
        $global->save();

        $deleted->delete();
        $left = Exemption::query()->orderBy('id')->get();
    } finally {
        RuleSchema::rollback();
    }

    expect($created)->toBeTrue()
        ->and(Schema::connection("exemptions-{$driver}")->hasTable('dynamic_throttle_exemptions'))->toBeFalse()
        ->and($left->pluck('id')->all())->toBe([$other->id, $global->id])
        ->and([$left[0]->subject, $left[0]->subject_parameter, $left[0]->value, $left[0]->rule_id, $left[0]->reason])
        ->toBe([ExemptionSubject::Custom, 'tenant', 'acme', $kept->id, 'A contract'])
        ->and($left[0]->expires_at?->getTimestamp())->toBe(FakeClock::T0 + 60)
        ->and([$left[1]->rule_id, $left[1]->expires_at])->toBe([null, null]);
})->with([
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);
