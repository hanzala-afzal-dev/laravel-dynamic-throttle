<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/*
| The package's tables store times in UTC, whatever the app's time zone, so a
| daylight-saving change cannot move an end time by the repeated hour
| (decided at the Phase 3 review, 2026-10-13; SPEC-009 AC-24, SPEC-013 AC-26).
| The app runs in Europe/Berlin, where 2026-10-25 02:00 to 03:00 happens twice:
| first in summer time (UTC+2), then in winter time (UTC+1).
*/

beforeEach(function (): void {
    config()->set('app.timezone', 'Europe/Berlin');
    date_default_timezone_set('Europe/Berlin');
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2026-10-24 12:00:00', 'UTC'));
});

afterEach(function (): void {
    date_default_timezone_set('UTC');
    ManifestRepository::flushState();
});

/**
 * 02:30 in Berlin on 2026-10-25, the first time: in summer time, 00:30 UTC. Its
 * wall-clock time alone reads back as the second 02:30, an hour later.
 */
function firstHalfPastTwo(): Carbon
{
    return Carbon::parse('2026-10-25 00:30:00', 'UTC')->setTimezone('Europe/Berlin');
}

it('stores an exemption\'s end time in UTC, and reads it back as the same instant, in the app\'s time zone', function (): void {
    $until = Carbon::parse('2027-07-01 12:00:00', 'Europe/Berlin');
    $exemption = Exemption::forIp('10.0.0.0/8')->until($until);
    $exemption->save();

    $read = Exemption::query()->sole()->expires_at;

    expect(DB::table('dynamic_throttle_exemptions')->value('expires_at'))->toBe('2027-07-01 10:00:00')
        ->and([$read?->getTimestamp(), $read?->getTimezone()->getName()])->toBe([$until->getTimestamp(), 'Europe/Berlin']);
});

it('stores a setting\'s end time in UTC', function (): void {
    Settings::bypass(until: Carbon::parse('2027-07-01 12:00:00', 'Europe/Berlin')->getTimestamp());

    expect(DB::table('dynamic_throttle_settings')->value('expires_at'))->toBe('2027-07-01 10:00:00');
});

it('stores the rules\' and the audit log\'s times in UTC', function (): void {
    Throttling::rule();
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m'])->assertExitCode(0);

    expect(DB::table('dynamic_throttle_rules')->value('created_at'))->toBe('2026-10-24 12:00:00')
        ->and(DB::table('dynamic_throttle_audit_log')->value('created_at'))->toBe('2026-10-24 12:00:00')
        ->and(DB::table('dynamic_throttle_settings')->value('expires_at'))->toBe('2026-10-24 12:30:00');
});

it('compiles an end time in the repeated hour of a daylight-saving change to the instant it was given, not an hour later', function (): void {
    Exemption::forIp('10.0.0.0/8')->until(firstHalfPastTwo())->save();
    Settings::bypass(until: firstHalfPastTwo()->getTimestamp());

    expect(Exemptions::stored()['exemptions'][0]['expires_at'])->toBe(firstHalfPastTwo()->getTimestamp())
        ->and(Settings::stored()['bypass_all']['until'] ?? null)->toBe(firstHalfPastTwo()->getTimestamp());
});
