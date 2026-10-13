<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Health;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/*
| SPEC-029 AC-5: the `emergency` health check, for an emergency setting left
| on. It warns about bypass or a scale factor that servers apply with no end
| time, or with one more than 24 hours away, and is ok while one ends within
| 24 hours. SQLite and the array cache store; the clock stands at
| 2027-01-15 08:00:00 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 08:00:00', 'UTC'));
    Throttling::rule();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-5: is ok when no setting applies, such as one that has ended', function (Closure $settings): void {
    $settings();

    expect(Health::check('emergency'))->toBe(['ok', 'No emergency setting applies.']);
})->with([
    'none' => [fn (): null => null],
    'bypass that has ended' => [function (): void {
        Settings::bypass(until: Settings::at('2027-01-15 08:30:00'));
        test()->travelTo(Carbon::parse('2027-01-15 08:31:00', 'UTC'));
    }],
]);

it('AC-5: is ok with bypass or a scale factor that ends within 24 hours, naming it and its end time', function (Closure $settings, string $named): void {
    $settings();

    expect(Health::check('emergency'))->toBe(['ok', $named]);
})->with([
    'bypass for 30 minutes' => [
        fn (): mixed => Settings::bypass(until: Settings::at('2027-01-15 08:30:00')),
        'Bypass applies until 2027-01-15 08:30:00 UTC.',
    ],
    'a factor for 2 hours' => [
        fn (): mixed => Settings::scale('2', until: Settings::at('2027-01-15 10:00:00')),
        'The scale factor 2 applies until 2027-01-15 10:00:00 UTC.',
    ],
    'bypass that ends exactly 24 hours ahead' => [
        fn (): mixed => Settings::bypass(until: Settings::at('2027-01-16 08:00:00')),
        'Bypass applies until 2027-01-16 08:00:00 UTC.',
    ],
]);

it('AC-5: warns about one that ends later, or never, naming it, its end time or no end time, and how to end it', function (Closure $settings, string $named): void {
    $settings();

    expect(Health::check('emergency'))->toBe(['warning', $named]);
})->with([
    'bypass with no end time' => [
        fn (): mixed => Settings::bypass(),
        'Bypass applies with no end time: end it with php artisan dynamic-throttle:bypass off once the emergency is over.',
    ],
    'bypass a second past 24 hours' => [
        fn (): mixed => Settings::bypass(until: Settings::at('2027-01-16 08:00:01')),
        'Bypass applies until 2027-01-16 08:00:01 UTC, more than a day away: end it with php artisan dynamic-throttle:bypass off once the emergency is over.',
    ],
    'a factor with no end time' => [
        fn (): mixed => Settings::scale('0.5'),
        'The scale factor 0.5 applies with no end time: end it with php artisan dynamic-throttle:scale off once the emergency is over.',
    ],
    'a factor for a week' => [
        fn (): mixed => Settings::scale('1.5', until: Settings::at('2027-01-22 08:00:00')),
        'The scale factor 1.5 applies until 2027-01-22 08:00:00 UTC, more than a day away: end it with php artisan dynamic-throttle:scale off once the emergency is over.',
    ],
]);

it('AC-5: warns when one of two settings lasts, naming both', function (): void {
    Settings::bypass(until: Settings::at('2027-01-15 08:30:00'));
    Settings::scale('2');

    expect(Health::check('emergency'))->toBe(['warning', 'Bypass applies until 2027-01-15 08:30:00 UTC. The scale factor 2 applies with no end time: end it with php artisan dynamic-throttle:scale off once the emergency is over.']);
});

it('AC-5: is skipped without a manifest, since servers then apply no setting, and the manifest check errs', function (): void {
    Settings::bypass();
    Cache::forget(app(KeyBuilder::class)->manifest());

    expect(Health::check('emergency'))->toBe(['skipped', 'Without a manifest, servers apply no setting: see the manifest check.']);
});

it('AC-5: changes nothing, in the database or the cache store', function (): void {
    Settings::bypass();
    $before = [DB::table('dynamic_throttle_settings')->get()->all(), Cache::get(app(KeyBuilder::class)->manifest())];

    Health::report();

    expect([DB::table('dynamic_throttle_settings')->get()->all(), Cache::get(app(KeyBuilder::class)->manifest())])->toEqual($before);
});
