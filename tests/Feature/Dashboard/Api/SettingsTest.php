<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\AuditEntry;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-017 AC-4, AC-17, AC-19 and AC-22: reading bypass and the scale factor,
| changing them as the CLI does, with the user as the audit entry's actor, and
| the API's JSON errors. The clock stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

const SETTINGS_OFF = ['bypass' => ['on' => false, 'until' => null], 'scale' => ['factor' => null, 'until' => null]];

/*
| AC-4: reading.
*/

it('AC-4: shows bypass and the scale factor, on or off, and until when', function (): void {
    Settings::bypass(Settings::at('2027-01-15 15:30:00'));
    Settings::scale('0.5');

    $response = DashboardApi::send($this, 'GET', 'settings', user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and($response->json())->toBe([
            'bypass' => ['on' => true, 'until' => '2027-01-15T15:30:00+00:00'],
            'scale' => ['factor' => '0.5', 'until' => null],
        ]);
});

it('AC-4: shows off for settings never set, and for settings that have ended', function (): void {
    expect(DashboardApi::send($this, 'GET', 'settings', user: DashboardApi::viewer())->json())->toBe(SETTINGS_OFF);

    Settings::bypass(Settings::at('2027-01-15 12:30:00'));
    Settings::scale('2', Settings::at('2027-01-15 12:30:00'));
    $this->travelTo(Carbon::parse('2027-01-15 12:30:00', 'UTC'));

    expect(DashboardApi::send($this, 'GET', 'settings', user: DashboardApi::viewer())->json())->toBe(SETTINGS_OFF);
});

/*
| AC-17: changing them.
*/

it('AC-17: turns bypass on for a while, records the user, the IP and the user agent, and says what the compile did', function (): void {
    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '2h', 'confirm' => true], DashboardApi::manager());

    $until = '2027-01-15T14:00:00+00:00';

    expect($response->status())->toBe(200)
        ->and($response->json())->toBe([
            'settings' => ['bypass' => ['on' => true, 'until' => $until], 'scale' => ['factor' => null, 'until' => null]],
            'changed' => true,
            'manifest' => ['compiled' => true, 'version' => Exemptions::stored()['version']],
        ])
        ->and(Settings::audit())->toBe([[
            'actor' => DashboardApi::managerActor(),
            'action' => 'settings.bypass',
            'target' => 'setting:bypass_all',
            'before' => null,
            'after' => ['value' => '1', 'expires_at' => $until],
            'ip' => '127.0.0.1',
            'user_agent' => DashboardApi::USER_AGENT,
        ]]);
});

it('AC-17: refuses bypass on without confirm, with a 409 that says what it does, and saves and records nothing', function (): void {
    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '2h'], DashboardApi::manager());

    expect($response->status())->toBe(409)
        ->and($response->json('message'))->toBe('Bypass lifts every limit until 2027-01-15 14:00 UTC.')
        ->and([Setting::query()->count(), Settings::audit()])->toBe([0, []]);
});

it('AC-17: turns bypass off, recording the setting it ended, and needs no confirm', function (): void {
    Settings::bypass();

    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'off'], DashboardApi::manager());

    expect([$response->status(), $response->json('changed'), $response->json('settings')])->toBe([200, true, SETTINGS_OFF])
        ->and(Settings::audit())->toHaveCount(1)
        ->and(Settings::audit()[0]['before'])->toBe(['value' => '1', 'expires_at' => null])
        ->and(Settings::audit()[0]['after'])->toBeNull();
});

it('AC-17: answers changed false, with no manifest, and records nothing, when a setting is off already', function (string $uri, array $input): void {
    $response = DashboardApi::send($this, 'PUT', $uri, $input, DashboardApi::manager());

    expect($response->status())->toBe(200)
        ->and($response->json())->toBe(['settings' => SETTINGS_OFF, 'changed' => false, 'manifest' => null])
        ->and(Settings::audit())->toBe([]);
})->with([
    'bypass' => ['settings/bypass', ['state' => 'off']],
    'scale' => ['settings/scale', ['factor' => 'off']],
]);

it('AC-17: sets the scale factor, needs confirm below 1, and records settings.scale', function (): void {
    $refused = DashboardApi::send($this, 'PUT', 'settings/scale', ['factor' => '0.5', 'for' => '30m'], DashboardApi::manager());
    $lowered = DashboardApi::send($this, 'PUT', 'settings/scale', ['factor' => '0.5', 'for' => '30m', 'confirm' => true], DashboardApi::manager());
    $raised = DashboardApi::send($this, 'PUT', 'settings/scale', ['factor' => '1.5', 'forever' => true], DashboardApi::manager());

    expect([$refused->status(), $refused->json('message')])->toBe([409, 'Every limit is multiplied by 0.5 until 2027-01-15 12:30 UTC, so clients are limited sooner.'])
        ->and([$lowered->status(), $lowered->json('settings.scale')])->toBe([200, ['factor' => '0.5', 'until' => '2027-01-15T12:30:00+00:00']])
        ->and([$raised->status(), $raised->json('settings.scale')])->toBe([200, ['factor' => '1.5', 'until' => null]])
        ->and(array_column(Settings::audit(), 'action'))->toBe(['settings.scale', 'settings.scale'])
        ->and(array_column(Settings::audit(), 'actor'))->toBe([DashboardApi::managerActor(), DashboardApi::managerActor()]);
});

it('AC-17: turns the scale factor off', function (): void {
    Settings::scale('2');

    $response = DashboardApi::send($this, 'PUT', 'settings/scale', ['factor' => 'off'], DashboardApi::manager());

    expect([$response->status(), $response->json('changed'), $response->json('settings.scale')])->toBe([200, true, ['factor' => null, 'until' => null]])
        ->and(Setting::query()->count())->toBe(0);
});

it('AC-17: refuses what the CLI refuses, with a 422 that names the field', function (string $uri, array $input, string $field): void {
    $response = DashboardApi::send($this, 'PUT', $uri, $input, DashboardApi::manager());

    expect($response->status())->toBe(422)
        ->and(array_keys($response->json('errors')))->toContain($field)
        ->and([Setting::query()->count(), Settings::audit()])->toBe([0, []]);
})->with([
    'no state' => ['settings/bypass', [], 'state'],
    'a state that is neither' => ['settings/bypass', ['state' => 'maybe'], 'state'],
    'for and forever' => ['settings/bypass', ['state' => 'on', 'for' => '1h', 'forever' => true, 'confirm' => true], 'for'],
    'over 365 days' => ['settings/bypass', ['state' => 'on', 'for' => '366d', 'confirm' => true], 'for'],
    'a factor under 0.1' => ['settings/scale', ['factor' => '0.05', 'confirm' => true], 'factor'],
    'three decimals' => ['settings/scale', ['factor' => '1.234'], 'factor'],
]);

it('AC-17: lets a viewer change nothing', function (): void {
    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true], DashboardApi::viewer());

    expect([$response->status(), Setting::query()->count(), Settings::audit()])->toBe([403, 0, []]);
});

/*
| AC-19: the audit table.
*/

it('AC-19: answers 503 with the way out when the audit table is missing, and saves nothing', function (): void {
    Schema::connection((new AuditEntry)->getConnectionName())->drop((new AuditEntry)->getTable());

    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true], DashboardApi::manager());

    expect($response->status())->toBe(503)
        ->and($response->json('message'))->toStartWith('The dynamic_throttle_audit_log table does not exist. Publish the package\'s migrations')
        ->and(Setting::query()->count())->toBe(0);
});

it('AC-19: rolls the change back when its audit entry cannot be written', function (): void {
    Event::listen('eloquent.creating: '.AuditEntry::class, function (): never {
        throw new RuntimeException('The disk is full.');
    });

    $response = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'confirm' => true], DashboardApi::manager());

    expect($response->status())->toBe(500)
        ->and(Setting::query()->count())->toBe(0);
});

/*
| AC-22: errors are JSON, never a redirect.
*/

it('AC-22: answers a 422 and a 409 in JSON with a message, also to a request that does not ask for JSON', function (): void {
    $invalid = DashboardApi::sendHtml($this, 'PUT', 'settings/bypass', ['state' => 'maybe'], DashboardApi::manager());
    $unconfirmed = DashboardApi::sendHtml($this, 'PUT', 'settings/bypass', ['state' => 'on'], DashboardApi::manager());

    expect([$invalid->status(), $invalid->headers->get('Content-Type'), is_string($invalid->json('message')), array_keys($invalid->json('errors'))])
        ->toBe([422, 'application/json', true, ['state']])
        ->and([$unconfirmed->status(), $unconfirmed->headers->get('Content-Type'), $unconfirmed->json('message')])
        ->toBe([409, 'application/json', 'Bypass lifts every limit until 2027-01-15 13:00 UTC.']);
});

it('AC-22: answers a change without a CSRF token with 419, as any web route does', function (): void {
    $response = $this->actingAs(DashboardApi::manager())->putJson(DashboardApi::PREFIX.'settings/bypass', ['state' => 'on', 'confirm' => true]);

    expect([$response->status(), Setting::query()->count()])->toBe([419, 0]);
});
