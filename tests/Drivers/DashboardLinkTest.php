<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Dashboard\Links\LinkToken;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| SPEC-034 AC-36, from the security review: a dashboard link is used once on MySQL
| and PostgreSQL, as on SQLite (AC-14), its times in UTC. Each test gets a random
| table prefix, so parallel runs never share a table.
*/

it('AC-36: uses a link once, and keeps its times in UTC', function (string $driver, string $host, int $port, string $extension): void {
    if (! extension_loaded($extension)) {
        $this->markTestSkipped("ext-{$extension} is not installed.");
    }

    config()->set("database.connections.links-{$driver}", [
        'driver' => $driver,
        'host' => $host,
        'port' => $port,
        'database' => 'dynamic_throttle',
        'username' => 'throttle',
        'password' => 'secret',
        'charset' => $driver === 'mysql' ? 'utf8mb4' : 'utf8',
        'prefix' => 'p'.bin2hex(random_bytes(4)).'_',
    ]);
    config()->set('dynamic-throttle.database.connection', "links-{$driver}");
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');
    $this->travelTo(Carbon::parse('2027-01-01 12:00:00', 'UTC'));

    RuleSchema::migrate();

    try {
        $controls = app(EmergencyControls::class);
        $token = LinkToken::make();
        $controls->createLink('alice', LinkToken::hash($token), false, Carbon::now()->addHour(), Actor::cli());

        $first = $controls->useLink(LinkToken::hash($token), '127.0.0.1', 'Pest');
        $second = $controls->useLink(LinkToken::hash($token), '127.0.0.1', 'Pest');
        $stored = (array) DB::connection("links-{$driver}")->table('dynamic_throttle_links')->first();
        $uses = DB::connection("links-{$driver}")->table('dynamic_throttle_audit_log')->where('action', 'link.use')->count();
    } finally {
        RuleSchema::rollback();
        date_default_timezone_set('UTC');
    }

    expect($first)->toBeInstanceOf(DashboardLink::class)
        ->and($second)->toBeNull()
        ->and($uses)->toBe(1)
        ->and(substr((string) $stored['used_at'], 0, 19))->toBe('2027-01-01 12:00:00')
        ->and(substr((string) $stored['expires_at'], 0, 19))->toBe('2027-01-01 13:00:00');
})->with([
    'MySQL' => ['mysql', (string) env('MYSQL_HOST', '127.0.0.1'), (int) env('MYSQL_PORT', 3307), 'pdo_mysql'],
    'PostgreSQL' => ['pgsql', (string) env('PGSQL_HOST', '127.0.0.1'), (int) env('PGSQL_PORT', 5433), 'pdo_pgsql'],
]);
