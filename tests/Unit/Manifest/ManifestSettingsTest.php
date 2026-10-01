<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;

covers(ManifestSettings::class);

/*
| SPEC-013: the runtime settings as the manifest holds them (ADR-0011). Each
| applies until its end time, in Unix seconds, and not at it (AC-9). The scale
| factor is a whole percentage. They travel as plain values, in the cache
| store's manifest and in L1 (AC-24).
*/

it('AC-5 and AC-6: applies nothing when no setting is on', function (): void {
    $settings = ManifestSettings::none();

    expect([$settings->bypasses(FakeClock::T0), $settings->scalePercent(FakeClock::T0), $settings->toArray()])
        ->toBe([false, 100, []]);
});

it('AC-9: bypasses until its end time, and not at it', function (): void {
    $settings = new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0 + 60);

    expect(array_map($settings->bypasses(...), [FakeClock::T0, FakeClock::T0 + 59, FakeClock::T0 + 60, FakeClock::T0 + 61]))
        ->toBe([true, true, false, false]);
});

it('AC-9: scales until its end time, and not at it', function (): void {
    $settings = new ManifestSettings(scale: 200, scaleUntil: FakeClock::T0 + 60);

    expect(array_map($settings->scalePercent(...), [FakeClock::T0, FakeClock::T0 + 59, FakeClock::T0 + 60, FakeClock::T0 + 61]))
        ->toBe([200, 200, 100, 100]);
});

it('AC-9: applies a setting with no end time for good', function (): void {
    $settings = new ManifestSettings(bypass: true, scale: 50);

    expect([$settings->bypasses(PHP_INT_MAX), $settings->scalePercent(PHP_INT_MAX)])->toBe([true, 50]);
});

it('AC-24: holds its settings as plain values, by name', function (): void {
    $settings = new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0 + 60, scale: 150);

    expect($settings->toArray())->toBe([
        'bypass_all' => ['until' => FakeClock::T0 + 60],
        'scale_factor' => ['percent' => 150, 'until' => null],
    ]);
});

it('AC-24: comes back from its plain values unchanged', function (ManifestSettings $settings): void {
    expect(ManifestSettings::fromArray($settings->toArray()))->toEqual($settings);
})->with([
    'none' => [fn (): ManifestSettings => ManifestSettings::none()],
    'bypass for good' => [fn (): ManifestSettings => new ManifestSettings(bypass: true)],
    'bypass until a time' => [fn (): ManifestSettings => new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0 + 60)],
    'a factor until a time' => [fn (): ManifestSettings => new ManifestSettings(scale: 10, scaleUntil: FakeClock::T0 + 60)],
    'both' => [fn (): ManifestSettings => new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0, scale: 1_000, scaleUntil: FakeClock::T0 + 1)],
]);

it('AC-24: rejects plain values that are not settings', function (mixed $settings): void {
    expect(fn (): ManifestSettings => ManifestSettings::fromArray($settings))->toThrow(InvalidArgumentException::class);
})->with([
    'a string' => ['bypass_all'],
    'a list' => [[['until' => null]]],
    'bypass that is not an object' => [['bypass_all' => true]],
    'bypass without its end time' => [['bypass_all' => []]],
    'bypass with a key too many' => [['bypass_all' => ['until' => null, 'value' => true]]],
    'bypass until a string' => [['bypass_all' => ['until' => '1800000060']]],
    'bypass until a float' => [['bypass_all' => ['until' => 1_800_000_060.5]]],
    'a factor that is not an object' => [['scale_factor' => 150]],
    'a factor without its percentage' => [['scale_factor' => ['until' => null]]],
    'a factor without its end time' => [['scale_factor' => ['percent' => 150]]],
    'a factor as a float' => [['scale_factor' => ['percent' => 1.5, 'until' => null]]],
    'a factor below 0.1' => [['scale_factor' => ['percent' => 9, 'until' => null]]],
    'a factor above 10' => [['scale_factor' => ['percent' => 1_001, 'until' => null]]],
    'a factor until a string' => [['scale_factor' => ['percent' => 150, 'until' => 'soon']]],
]);

it('AC-24: names a setting it does not know', function (): void {
    expect(fn (): ManifestSettings => ManifestSettings::fromArray(['maintenance' => ['until' => null]]))
        ->toThrow(InvalidArgumentException::class, 'The manifest has a setting it does not know: "maintenance".');
});

it('AC-24: refuses a factor outside 0.1 to 10, and an end time for a setting that is off', function (array $arguments): void {
    expect(fn (): ManifestSettings => new ManifestSettings(...$arguments))->toThrow(InvalidArgumentException::class);
})->with([
    'a factor below 0.1' => [['scale' => 9]],
    'a factor above 10' => [['scale' => 1_001]],
    'an end time without bypass' => [['bypassUntil' => FakeClock::T0]],
    'an end time without a factor' => [['scaleUntil' => FakeClock::T0]],
]);
