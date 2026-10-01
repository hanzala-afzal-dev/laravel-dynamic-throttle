<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-013: dynamic-throttle:scale (ADR-0011). AC-13 the command, AC-15 the
| confirmation in production, AC-23 the audit log. --for works as for bypass
| (BypassCommandTest). The clock stands at 2027-01-01 00:00:00 UTC.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-13: scales the limits until the end time, compiles, and says until when', function (string $factor, int $percent): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => $factor, '--for' => '2h'])
        ->expectsOutputToContain("Limits are scaled by {$factor} until 2027-01-01 02:00:00 UTC.")
        ->expectsOutputToContain('Servers apply it within 2 seconds.')
        ->assertExitCode(0);

    expect(Settings::stored())->toBe(['scale_factor' => ['percent' => $percent, 'until' => Settings::at('2027-01-01 02:00:00')]]);
})->with([
    '2' => ['2', 200],
    '0.5' => ['0.5', 50],
    '1.25' => ['1.25', 125],
]);

it('AC-13: scales the limits for one hour without --for, and with no end time with --forever', function (): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => '2'])
        ->expectsOutputToContain('Limits are scaled by 2 until 2027-01-01 01:00:00 UTC.')
        ->assertExitCode(0);
    $forOneHour = Settings::stored();

    $this->artisan('dynamic-throttle:scale', ['factor' => '3', '--forever' => true])
        ->expectsOutputToContain('Limits are scaled by 3, with no end time. Turn it off with php artisan dynamic-throttle:scale off.')
        ->assertExitCode(0);

    expect($forOneHour)->toBe(['scale_factor' => ['percent' => 200, 'until' => Settings::at('2027-01-01 01:00:00')]])
        ->and(Settings::stored())->toBe(['scale_factor' => ['percent' => 300, 'until' => null]])
        ->and(Setting::query()->count())->toBe(1);
});

it('AC-13: turns the factor off, and requests follow both', function (): void {
    Throttling::prepare();
    Throttling::rule(['max_attempts' => 10]);
    Throttling::route('api/x');

    $this->artisan('dynamic-throttle:scale', ['factor' => '2'])->assertExitCode(0);
    $scaled = $this->get('api/x');
    $this->artisan('dynamic-throttle:scale', ['factor' => 'off'])
        ->expectsOutputToContain('Limits are no longer scaled.')
        ->assertExitCode(0);
    $unscaled = $this->get('api/x');

    expect([Throttling::headers($scaled)['X-RateLimit-Limit'] ?? null, Throttling::headers($unscaled)['X-RateLimit-Limit'] ?? null])->toBe(['20', '10'])
        ->and(Settings::stored())->toBe([]);
});

it('AC-13: says the limits are not scaled, writes nothing, and exits 0', function (): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => 'off'])
        ->expectsOutputToContain('Limits are not scaled.')
        ->assertExitCode(0);

    expect(Settings::audit())->toBe([]);
});

it('AC-13: says the limits are not scaled once the factor has ended, writes nothing, and exits 0', function (): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => '2', '--for' => '30m'])->assertExitCode(0);
    $this->travelTo(Carbon::parse('2027-01-01 00:31:00', 'UTC'));

    $this->artisan('dynamic-throttle:scale', ['factor' => 'off'])
        ->expectsOutputToContain('Limits are not scaled.')
        ->assertExitCode(0);

    expect(Settings::audit())->toHaveCount(1);
});

it('AC-13: rejects a factor it cannot use, says why, and writes nothing', function (string $factor): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => $factor])
        ->expectsOutputToContain("The factor must be from 0.1 to 10, with at most two decimals, such as 1.5, got \"{$factor}\".")
        ->assertExitCode(1);

    expect([Setting::query()->count(), Settings::audit()])->toBe([0, []]);
})->with(['0.05', '11', 'abc', '1.234', '0', '-1', '1e1', '10.01', '.5', '']);

it('AC-13: rejects --for and --forever with off', function (): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => 'off', '--for' => '2h'])
        ->expectsOutputToContain('--for and --forever do not apply to off.')
        ->assertExitCode(1);
});

it('AC-15: asks in production before a factor below 1, and writes nothing on no', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:scale', ['factor' => '0.5'])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect(Setting::query()->count())->toBe(0);
});

it('AC-15: never asks for a factor of 1 or more, nor to turn the factor off', function (): void {
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:scale', ['factor' => '1'])->assertExitCode(0);
    $this->artisan('dynamic-throttle:scale', ['factor' => '2'])->assertExitCode(0);
    $this->artisan('dynamic-throttle:scale', ['factor' => 'off'])->assertExitCode(0);

    expect(Setting::query()->count())->toBe(0);
});

it('AC-23: writes one audit entry for each change, with the CLI\'s actor, and the state before and after', function (): void {
    $this->artisan('dynamic-throttle:scale', ['factor' => '2', '--for' => '2h'])->assertExitCode(0);
    $this->artisan('dynamic-throttle:scale', ['factor' => '0.5', '--forever' => true])->assertExitCode(0);
    $this->artisan('dynamic-throttle:scale', ['factor' => 'off'])->assertExitCode(0);

    $double = ['value' => '2', 'expires_at' => '2027-01-01T02:00:00+00:00'];
    $half = ['value' => '0.5', 'expires_at' => null];
    $entry = fn (?array $before, ?array $after): array => [
        'actor' => Settings::cliActor(),
        'action' => 'settings.scale',
        'target' => 'setting:scale_factor',
        'before' => $before,
        'after' => $after,
        'ip' => null,
        'user_agent' => null,
    ];

    expect(Settings::audit())->toBe([$entry(null, $double), $entry($double, $half), $entry($half, null)]);
});
