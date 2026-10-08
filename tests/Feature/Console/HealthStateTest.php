<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/*
| SPEC-016 AC-12 and AC-13: the checks of the counter store's state, the
| manifest and the flush. SQLite and a store kept in memory; the clock stands
| on 2027-01-15, in UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

function healthTravel(string $time): void
{
    test()->travelTo(Carbon::parse("2027-01-15 {$time}", 'UTC'));
}

it('AC-12: is ok with a manifest, giving its version, its rules and exemptions, and when it was compiled', function (): void {
    healthTravel('10:57:30');
    Health::passing();
    healthTravel('11:00:30');

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe('ok')
        ->and($message)->toContain('1800010650000')->toContain('1 rule')->toContain('0 exemptions')->toContain('10:57:30');
});

it('AC-12: errs without a manifest, saying to compile it', function (): void {
    healthTravel('11:00:30');
    Health::passing();
    Cache::store('health')->forget(app(KeyBuilder::class)->manifest());

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe('error')
        ->and($message)->toContain('php artisan dynamic-throttle:compile');
});

it('AC-12: errs with a manifest that cannot be decoded', function (): void {
    healthTravel('11:00:30');
    Health::passing();
    Cache::store('health')->forever(app(KeyBuilder::class)->manifest(), '{"format": 3');

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe('error')
        ->and($message)->toContain('The manifest is not JSON');
});

it('AC-12: errs when the store cannot be read, naming why', function (): void {
    healthTravel('11:00:30');
    Health::passing();
    Health::storeDown();

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe('error')
        ->and($message)->toContain('The cache is down.');
});

it('AC-12: warns when the scheduled compile has not rewritten the manifest for 10 minutes', function (): void {
    healthTravel('10:49:30');
    Health::passing();
    healthTravel('11:00:30');

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe('warning')
        ->and($message)->toContain('11 minutes')->toContain('scheduler');
});

it('AC-12: is ok with a manifest compiled 10 minutes ago, or longer ago when the app schedules no compile', function (string $compiled, bool $scheduled): void {
    healthTravel($compiled);
    Health::passing();
    config()->set('dynamic-throttle.manifest.schedule', $scheduled);
    healthTravel('11:00:30');

    expect(Health::status('manifest'))->toBe('ok');
})->with([
    '10 minutes ago' => ['10:50:30', true],
    'a day ago, unscheduled' => ['00:00:00', false],
]);

it('AC-12: warns about more than 500 rules or 1,000 exemptions, which every load of the manifest pays for', function (int $rules, int $exemptions, ?string $named): void {
    healthTravel('11:00:30');
    Health::passing();
    Rule::withoutEvents(function () use ($rules): void {
        // Health::passing() saved the first.
        foreach ($rules > 1 ? range(2, $rules) : [] as $n) {
            Rule::create(RuleSchema::attributes(['name' => "Rule {$n}", 'pattern' => "api/{$n}/*"]));
        }
    });
    Exemption::withoutEvents(function () use ($exemptions): void {
        foreach (range(1, $exemptions) as $n) {
            Exemption::forIp('10.'.intdiv($n, 256).'.'.($n % 256).'.1')->save();
        }
    });
    Artisan::call('dynamic-throttle:compile');

    [$status, $message] = Health::check('manifest');

    expect($status)->toBe($named === null ? 'ok' : 'warning');

    if ($named !== null) {
        expect($message)->toContain($named);
    }
})->with([
    '500 rules and 1,000 exemptions' => [500, 1_000, null],
    '501 rules' => [501, 1, '501 rules'],
    '1,001 exemptions' => [1, 1_001, '1,001 exemptions'],
]);

it('AC-13: is ok with the metrics flushed through 10 minutes ago', function (): void {
    healthTravel('11:01:30');
    Health::passing();
    Telemetry::buffer()->markFlushed(Telemetry::minute(Carbon::parse('2027-01-15 10:51', 'UTC')->getTimestamp()));

    expect(Health::status('metrics'))->toBe('ok');
});

it('AC-13: warns when the metrics were last flushed more than 10 minutes ago, naming the minute', function (): void {
    healthTravel('11:01:30');
    Health::passing();
    Telemetry::buffer()->markFlushed(Telemetry::minute(Carbon::parse('2027-01-15 10:50', 'UTC')->getTimestamp()));

    [$status, $message] = Health::check('metrics');

    expect($status)->toBe('warning')
        ->and($message)->toContain('10:50')->toContain('11 minutes ago');
});

it('AC-13: warns when nothing was flushed in the last 2 hours, saying to check the scheduler', function (): void {
    healthTravel('11:01:30');
    Health::passing();
    Cache::store('health')->forget(app(KeyBuilder::class)->telemetryFlushed());

    [$status, $message] = Health::check('metrics');

    expect($status)->toBe('warning')
        ->and($message)->toContain('2 hours')->toContain('scheduler');
});

it('AC-13: is skipped with telemetry off', function (): void {
    healthTravel('11:01:30');
    Health::passing();
    config()->set('dynamic-throttle.telemetry.enabled', false);

    expect(Health::status('metrics'))->toBe('skipped');
});

it('AC-13: errs when the store cannot be read, naming why', function (): void {
    healthTravel('11:01:30');
    Health::passing();
    Health::storeDown();

    [$status, $message] = Health::check('metrics');

    expect($status)->toBe('error')
        ->and($message)->toContain('The cache is down.');
});
