<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/*
| SPEC-016 AC-16 to AC-19 on the cache driver: the checks of what the rules
| saw in the last 60 complete minutes, from telemetry. The clock stands at
| 2027-01-15 11:00:30 UTC, so those are 09:59 to 10:58. Rule 1 counts by IP,
| rule 2, Users, counts guests under their IP, and rule 3, Members, skips them.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 11:00:30', 'UTC'));
    Health::passing();
    Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Throttling::rule(['name' => 'Members', 'scope' => Scope::User, 'on_missing_identity' => MissingIdentity::Skip]);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Records one request at a minute of 2027-01-15, such as "10:30", that stands for $weight.
 *
 * @param  list<TelemetryRecord>  $records
 */
function healthSaw(string $time, int $weight, array $records): void
{
    Telemetry::buffer()->record(Telemetry::minute(Carbon::parse("2027-01-15 {$time}", 'UTC')->getTimestamp()), $weight, $records);
}

it('AC-16: warns about a user rule that counted mostly guests, naming it and the share', function (): void {
    healthSaw('10:30', 90, [Telemetry::guest('2')]);
    healthSaw('10:31', 60, [Telemetry::counted('2')]);

    [$status, $message] = Health::check('guests');

    expect($status)->toBe('warning')
        ->and($message)->toContain('Users')->toContain('90 of 150')->toContain('60%');
});

it('AC-16: warns about a user skip rule that let mostly guests pass', function (): void {
    healthSaw('10:30', 120, [Telemetry::skipped('3')]);
    healthSaw('10:30', 80, [Telemetry::counted('3')]);

    [$status, $message] = Health::check('guests');

    expect($status)->toBe('warning')
        ->and($message)->toContain('Members')->toContain('120 of 200')->toContain('60%')
        ->not->toContain('Users');
});

it('AC-16: counts the 60 complete minutes, and only those', function (string $first, string $last, string $status): void {
    healthSaw($first, 90, [Telemetry::guest('2')]);
    healthSaw($last, 60, [Telemetry::counted('2')]);

    expect(Health::status('guests'))->toBe($status);
})->with([
    'the first and the last complete minute' => ['09:59', '10:58', 'warning'],
    // The guests fall outside: what is left is 60 users.
    'the minute before them' => ['09:58', '10:58', 'ok'],
    'the minute that is not complete yet' => ['10:59', '10:58', 'ok'],
]);

it('AC-16: is ok below half, and with too few requests to tell', function (int $guests, int $users): void {
    healthSaw('10:30', $guests, [Telemetry::guest('2')]);
    healthSaw('10:30', $users, [Telemetry::counted('2')]);

    expect(Health::status('guests'))->toBe('ok');
})->with([
    '40 of 150' => [40, 110],
    '74 of 149' => [74, 75],
    '80 of 99' => [80, 19],
]);

it('AC-16: warns at exactly half of exactly 100 requests', function (): void {
    healthSaw('10:30', 50, [Telemetry::guest('2')]);
    healthSaw('10:30', 50, [Telemetry::counted('2')]);

    expect(Health::status('guests'))->toBe('warning');
});

it('AC-16: never checks a rule that is not a user rule', function (): void {
    healthSaw('10:30', 150, [Telemetry::guest('1')]);
    healthSaw('10:30', 150, [Telemetry::skipped('1')]);

    expect(Health::status('guests'))->toBe('ok');
});

it('AC-17: is skipped on the cache driver, which keeps no traffic per client', function (): void {
    [$status, $message] = Health::check('dominant-ip');

    expect($status)->toBe('skipped')
        ->and($message)->toContain('Redis');
});

it('AC-18: warns about a rule ignored over the cap, naming it, the count and the cap', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 4);
    healthSaw('10:30', 30, [Telemetry::ignored('2')]);

    [$status, $message] = Health::check('ignored');

    expect($status)->toBe('warning')
        ->and($message)->toContain('Users')->toContain('30')->toContain('matching.max_rules_per_request')->toContain('4');
});

it('AC-18: is ok when no rule was ignored', function (): void {
    healthSaw('10:30', 30, [Telemetry::counted('2')]);

    expect(Health::status('ignored'))->toBe('ok');
});

it('AC-19: skips the checks of what the rules saw, saying why', function (Closure $setup, array $statuses, string $said): void {
    healthSaw('10:30', 150, [Telemetry::guest('2'), Telemetry::ignored('3')]);
    $setup();

    $checks = Health::checks();

    expect([$checks['guests'][0], $checks['dominant-ip'][0], $checks['ignored'][0]])->toBe($statuses)
        ->and($checks['guests'][1])->toContain($said)
        ->and($checks['ignored'][1])->toContain($said);
})->with([
    'telemetry off' => [fn () => config()->set('dynamic-throttle.telemetry.enabled', false), ['skipped', 'skipped', 'skipped'], 'telemetry'],
    'a sample rate of 0' => [fn () => config()->set('dynamic-throttle.telemetry.sample_rate', 0.0), ['skipped', 'skipped', 'skipped'], 'sample_rate'],
    'no manifest' => [fn (): bool => Cache::store('health')->forget(app(KeyBuilder::class)->manifest()), ['skipped', 'skipped', 'skipped'], 'manifest'],
    'a store that cannot be read' => [fn () => Health::storeDown(), ['skipped', 'skipped', 'skipped'], 'cannot be read'],
]);
