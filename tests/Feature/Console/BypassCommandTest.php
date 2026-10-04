<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-013: dynamic-throttle:bypass (ADR-0011). AC-12 the command, AC-14 its
| durations, AC-15 the confirmation in production, AC-21 and AC-22 a cache or
| a database that fails, AC-23 the audit log. The clock stands at
| 2027-01-01 00:00:00 UTC.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-12: turns bypass on until the end time, compiles, and says until when', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsOutputToContain('Bypass is on until 2027-01-01 00:30:00 UTC: every request goes through, unlimited.')
        ->expectsOutputToContain('Servers apply it within 2 seconds.')
        ->assertExitCode(0);

    expect(Settings::stored())->toBe(['bypass_all' => ['until' => Settings::at('2027-01-01 00:30:00')]]);
});

it('AC-12: turns bypass on for one hour without --for', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on'])
        ->expectsOutputToContain('Bypass is on until 2027-01-01 01:00:00 UTC')
        ->assertExitCode(0);

    expect(Settings::stored())->toBe(['bypass_all' => ['until' => Settings::at('2027-01-01 01:00:00')]]);
});

it('AC-12: turns bypass on with no end time with --forever', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--forever' => true])
        ->expectsOutputToContain('Bypass is on, with no end time: every request goes through, unlimited. Turn it off with php artisan dynamic-throttle:bypass off.')
        ->assertExitCode(0);

    expect(Settings::stored())->toBe(['bypass_all' => ['until' => null]]);
});

it('AC-12: says when servers apply a change with the manifest read on every request', function (): void {
    config()->set('dynamic-throttle.manifest.refresh_seconds', 0);

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on'])
        ->expectsOutputToContain('Servers apply it on their next request.')
        ->assertExitCode(0);
});

it('AC-12: turns bypass off, and requests follow both', function (): void {
    Throttling::prepare();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);
    $bypassed = $this->get('api/x');
    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])
        ->expectsOutputToContain('Bypass is off: the rules apply again.')
        ->assertExitCode(0);
    $counted = $this->get('api/x');

    expect([Throttling::headers($bypassed), Throttling::headers($counted)['X-RateLimit-Remaining'] ?? null])->toBe([[], '0'])
        ->and(Settings::stored())->toBe([])
        ->and(Setting::query()->count())->toBe(0);
});

it('AC-12: says bypass is already off, writes nothing, and exits 0', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])
        ->expectsOutputToContain('Bypass is already off.')
        ->assertExitCode(0);

    expect(Settings::audit())->toBe([]);
});

it('AC-12: says bypass is already off once it has ended, writes nothing, and exits 0', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);
    $this->travelTo(Carbon::parse('2027-01-01 00:31:00', 'UTC'));

    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])
        ->expectsOutputToContain('Bypass is already off.')
        ->assertExitCode(0);

    expect(Settings::audit())->toHaveCount(1);
});

it('AC-12: rejects what it cannot do, says why, and writes nothing', function (array $arguments, string $message): void {
    $this->artisan('dynamic-throttle:bypass', $arguments)
        ->expectsOutputToContain($message)
        ->assertExitCode(1);

    expect([Setting::query()->count(), Settings::audit()])->toBe([0, []]);
})->with([
    'a state other than on or off' => [['state' => 'maybe'], 'The state must be on or off, got "maybe".'],
    '--for with --forever' => [['state' => 'on', '--for' => '30m', '--forever' => true], 'Pass --for or --forever, not both.'],
    '--for with off' => [['state' => 'off', '--for' => '30m'], '--for and --forever do not apply to off.'],
    '--forever with off' => [['state' => 'off', '--forever' => true], '--for and --forever do not apply to off.'],
    '--for without a duration, which would mean one hour by mistake' => [['state' => 'on', '--for' => null], '--for needs a duration, such as 30m.'],
]);

it('AC-14: takes a duration in seconds, minutes, hours or days, up to 365 days', function (string $for, string $until): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => $for])->assertExitCode(0);

    expect(Settings::stored()['bypass_all']['until'] ?? null)->toBe(Settings::at($until));
})->with([
    '90s' => ['90s', '2027-01-01 00:01:30'],
    '30m' => ['30m', '2027-01-01 00:30:00'],
    '2h' => ['2h', '2027-01-01 02:00:00'],
    '1d' => ['1d', '2027-01-02 00:00:00'],
    '365d' => ['365d', '2028-01-01 00:00:00'],
]);

it('AC-14: rejects a duration it cannot read, says why, and writes nothing', function (string $for): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => $for])
        ->expectsOutputToContain("--for must be a whole number and a unit, s, m, h or d, such as 30m, from 1 second to 365 days, got \"{$for}\".")
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
})->with(['0m', '-5m', '5', '5w', '366d', 'soon', '1.5h', '30 m']);

it('AC-15: asks in production before turning bypass on, and writes nothing on no', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
});

it('AC-15: turns bypass on in production after yes, and with --force without asking', function (array $arguments, bool $asks): void {
    $this->app['env'] = 'production';
    $command = $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m', ...$arguments]);

    if ($asks) {
        $command->expectsConfirmation('Are you sure you want to run this command?', 'yes');
    }

    // A pending command runs when it is run or destroyed: run it before reading what it wrote.
    $command->assertExitCode(0)->run();

    expect(Setting::query()->count())->toBe(1);
})->with([
    'yes' => [[], true],
    '--force' => [['--force' => true], false],
]);

it('AC-15: writes nothing in production without a terminal and without --force', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m', '--no-interaction' => true])
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
});

it('AC-15: never asks outside production, nor in production to turn bypass off', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])->assertExitCode(0);

    expect(Setting::query()->count())->toBe(0);
});

it('AC-21: keeps the change when the compile fails, says servers keep the previous manifest, and exits 1', function (): void {
    $store = new class extends ArrayStore
    {
        public function forever($key, $value): bool
        {
            throw new RuntimeException('The store is down.');
        }
    };
    app()->instance(ManifestCompiler::class, new ManifestCompiler(new Repository($store), new KeyBuilder('dt:')));

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsOutputToContain('The change is saved, but the manifest could not be compiled: The store is down.')
        ->expectsOutputToContain('Servers keep the previous manifest until a compile succeeds: run php artisan dynamic-throttle:compile, or wait for the scheduled one.')
        // Servers do not apply it yet, so the command never says they do (review).
        ->doesntExpectOutputToContain('every request goes through')
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(1)
        ->and(Settings::audit())->toHaveCount(1);
});

it('AC-21: says to compile by hand when no compile is scheduled', function (): void {
    config()->set('dynamic-throttle.manifest.schedule', false);
    $store = new class extends ArrayStore
    {
        public function forever($key, $value): bool
        {
            throw new RuntimeException('The store is down.');
        }
    };
    app()->instance(ManifestCompiler::class, new ManifestCompiler(new Repository($store), new KeyBuilder('dt:')));

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsOutputToContain('Servers keep the previous manifest until a compile succeeds: run php artisan dynamic-throttle:compile.')
        ->doesntExpectOutputToContain('wait for the scheduled one')
        ->assertExitCode(1);
});

it('AC-22: prints the error and exits 1 when the database cannot be reached, and requests keep the last compiled settings', function (): void {
    Throttling::prepare();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    Settings::bypass();
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/dynamic-throttle.sqlite', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'unreachable');

    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])
        ->expectsOutputToContain('Could not save the change:')
        ->assertExitCode(1);

    expect(Throttling::headers($this->get('api/x')))->toBe([]);
});

it('AC-23: writes one audit entry for each change, with the CLI\'s actor, and the state before and after', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--forever' => true])->assertExitCode(0);
    $this->artisan('dynamic-throttle:bypass', ['state' => 'off'])->assertExitCode(0);

    $until = ['value' => '1', 'expires_at' => '2027-01-01T00:30:00+00:00'];
    $forever = ['value' => '1', 'expires_at' => null];
    $entry = fn (?array $before, ?array $after): array => [
        'actor' => Settings::cliActor(),
        'action' => 'settings.bypass',
        'target' => 'setting:bypass_all',
        'before' => $before,
        'after' => $after,
        'ip' => null,
        'user_agent' => null,
    ];

    expect(Settings::audit())->toBe([$entry(null, $until), $entry($until, $forever), $entry($forever, null)]);
});

it('AC-23: writes neither the change nor its audit entry when the audit entry fails', function (): void {
    DB::statement("CREATE TRIGGER audit_down BEFORE INSERT ON dynamic_throttle_audit_log BEGIN SELECT RAISE(ABORT, 'The audit log is down.'); END");

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsOutputToContain('Could not save the change:')
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
});

it('says how to publish and run the migrations when a table is missing, and writes nothing', function (string $table): void {
    Schema::drop($table);

    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])
        ->expectsOutputToContain("The {$table} table does not exist. Publish the package's migrations with php artisan vendor:publish --tag=dynamic-throttle-migrations, delete the new copies of those you had already run, then run php artisan migrate.")
        ->assertExitCode(1);

    expect(Schema::hasTable('dynamic_throttle_settings') ? Setting::query()->count() : 0)->toBe(0);
})->with(['dynamic_throttle_settings', 'dynamic_throttle_audit_log']);
