<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Compiler\InvalidSettingSkipped;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Compiler\SettingsTableMissing;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-013 AC-2 to AC-4: the settings table compiled into the manifest
| (ADR-0011, ADR-0007). The clock stands at T0, for the compiler and for
| requests alike.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-2: compiles each setting that is on, with its end time in Unix seconds', function (): void {
    Settings::bypass(until: FakeClock::T0 + 60);
    Settings::scale('2');

    expect(Settings::stored())->toBe([
        'bypass_all' => ['until' => FakeClock::T0 + 60],
        'scale_factor' => ['percent' => 200, 'until' => null],
    ]);
});

it('AC-2: compiles a factor with two decimals exactly', function (string $factor, int $percent): void {
    Settings::scale($factor);

    expect(Settings::stored()['scale_factor']['percent'] ?? null)->toBe($percent);
})->with([
    '0.1' => ['0.1', 10],
    '0.25' => ['0.25', 25],
    '1.1' => ['1.1', 110],
    '1.5' => ['1.5', 150],
    '10' => ['10', 1_000],
]);

it('AC-2: leaves out a setting that ended before the compile, and keeps one that ends later', function (): void {
    Settings::insert(['expires_at' => Carbon::createFromTimestamp(FakeClock::T0)->toDateTimeString()]);
    Settings::insert(['name' => 'scale_factor', 'value' => '2', 'expires_at' => Carbon::createFromTimestamp(FakeClock::T0 + 1)->toDateTimeString()]);

    app(ManifestCompiler::class)->compile();

    expect(Settings::stored())->toBe(['scale_factor' => ['percent' => 200, 'until' => FakeClock::T0 + 1]]);
});

it('AC-2: writes no setting when none is on', function (): void {
    app(ManifestCompiler::class)->compile();

    expect(Exemptions::json())->toContain('"settings":{}');
});

it('AC-3: skips and reports a row that is not a valid setting, and still compiles the other', function (array $invalid, string $reason): void {
    Exceptions::fake();
    $skipped = Settings::insert($invalid);
    Settings::insert(['name' => $invalid['name'] === 'bypass_all' ? 'scale_factor' : 'bypass_all', 'value' => $invalid['name'] === 'bypass_all' ? '2' : '1']);

    app(ManifestCompiler::class)->compile();

    expect(array_keys(Settings::stored()))->toBe([$invalid['name'] === 'bypass_all' ? 'scale_factor' : 'bypass_all']);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (InvalidSettingSkipped $exception): bool => str_starts_with($exception->getMessage(), "Setting {$skipped} was skipped: ")
        && str_contains($exception->getMessage(), $reason));
})->with([
    'an unknown name' => [['name' => 'maintenance'], '"maintenance"'],
    'a factor of 0.05' => [['name' => 'scale_factor', 'value' => '0.05'], '"0.05"'],
    'a factor of 11' => [['name' => 'scale_factor', 'value' => '11'], '"11"'],
    'a factor that is not a number' => [['name' => 'scale_factor', 'value' => 'abc'], '"abc"'],
    'a factor with three decimals' => [['name' => 'scale_factor', 'value' => '1.234'], '"1.234"'],
    'a bypass that is not 1' => [['name' => 'bypass_all', 'value' => 'yes'], '"yes"'],
    'an end time that is not a date' => [['name' => 'bypass_all', 'expires_at' => 'soon'], 'expires_at'],
]);

it('AC-4: compiles after a setting is saved, updated or deleted, and not for a save that rolls back', function (): void {
    Exemptions::rule();
    $initial = Exemptions::stored();

    try {
        DB::transaction(function (): void {
            Settings::bypass();

            throw new RuntimeException('Roll back.');
        });
    } catch (RuntimeException) {
        // Expected: the transaction rolled back.
    }

    $rolledBack = Exemptions::stored();
    $setting = DB::transaction(fn () => Settings::scale('2'));
    $afterSave = Exemptions::stored();
    $setting->update(['value' => '3']);
    $afterUpdate = Exemptions::stored();
    $setting->delete();
    $afterDelete = Exemptions::stored();

    expect($rolledBack)->toBe($initial)
        ->and($afterSave['settings'])->toBe(['scale_factor' => ['percent' => 200, 'until' => null]])
        ->and($afterSave['version'])->toBeGreaterThan($initial['version'])
        ->and($afterUpdate['settings'])->toBe(['scale_factor' => ['percent' => 300, 'until' => null]])
        ->and($afterDelete['settings'])->toBe([])
        ->and($afterDelete['version'])->toBeGreaterThan($afterUpdate['version']);
});

it('compiles the rules and exemptions alone, and reports the missing table, before the settings migration has run', function (): void {
    Exceptions::fake();
    $rule = Exemptions::rule();
    Schema::drop('dynamic_throttle_settings');

    app(ManifestCompiler::class)->compile();

    expect(Settings::stored())->toBe([])
        ->and(array_column(Exemptions::stored()['rules'], 'id'))->toBe([(string) $rule->id]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (SettingsTableMissing $exception): bool => str_contains($exception->getMessage(), 'dynamic_throttle_settings'));
});
