<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| SPEC-013's changes, as EmergencyControls makes them for the commands now and
| for the JSON API and the dashboard later. Found by the review: the manifest is
| compiled once, by the model observer after the commit (ADR-0007), so a change
| inside a surrounding transaction compiles only if that transaction commits,
| and no model event is silenced. Concurrent changes neither fail nor audit a
| change twice.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('compiles once per change, and the models\' events still reach the app\'s listeners', function (): void {
    $rule = Exemptions::rule();
    $version = Exemptions::stored()['version'];
    $updated = 0;
    Event::listen('eloquent.updated: '.Rule::class, function () use (&$updated): void {
        $updated++;
    });

    $outcome = app(EmergencyControls::class)->setRuleEnabled($rule, false, 'cli:deploy');

    expect([$outcome->changed, $outcome->compiled?->version, $updated])->toBe([true, $version + 1, 1])
        ->and(Exemptions::stored()['version'])->toBe($version + 1);
});

it('leaves the manifest as it was when a surrounding transaction rolls back', function (): void {
    Exemptions::rule();
    $before = Exemptions::stored();

    try {
        DB::transaction(function (): void {
            app(EmergencyControls::class)->bypass(null, 'cli:deploy');

            throw new RuntimeException('Roll back.');
        });
    } catch (RuntimeException) {
        // Expected: the surrounding transaction rolled back.
    }

    expect(Exemptions::stored())->toBe($before)
        ->and([Setting::query()->count(), Settings::audit()])->toBe([0, []]);
});

it('compiles when a surrounding transaction commits, and says meanwhile that the compile is still to come', function (): void {
    Exemptions::rule();

    $outcome = DB::transaction(fn () => app(EmergencyControls::class)->bypass(null, 'cli:deploy'));

    expect([$outcome->changed, $outcome->compiled, $outcome->compileFailed])->toBe([true, null, null])
        ->and(Settings::stored())->toBe(['bypass_all' => ['until' => null]]);
});

it('reads the rule again in its transaction, so a change another admin already made is not made twice', function (): void {
    $rule = Exemptions::rule();
    $stale = Rule::query()->findOrFail($rule->id);
    // Another admin disables the rule after this one loaded it.
    app(EmergencyControls::class)->setRuleEnabled($rule, false, 'cli:alice');

    $outcome = app(EmergencyControls::class)->setRuleEnabled($stale, false, 'cli:bob');

    expect($outcome->changed)->toBeFalse()
        ->and(array_column(Settings::audit(), 'actor'))->toBe(['cli:alice']);
});

it('tries once more when another process saved the same setting first, so the last change wins', function (): void {
    $raced = false;
    // Another process saves bypass between this change's read of the row and its insert.
    DB::listen(function ($query) use (&$raced): void {
        if (! $raced && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "dynamic_throttle_settings"')) {
            $raced = true;
            DB::table('dynamic_throttle_settings')->insert(['name' => 'bypass_all', 'value' => '1', 'expires_at' => null]);
        }
    });

    $outcome = app(EmergencyControls::class)->bypass(Carbon::parse('2027-01-01 00:30:00', 'UTC'), 'cli:deploy');

    expect([$raced, $outcome->changed])->toBe([true, true])
        ->and(Settings::stored())->toBe(['bypass_all' => ['until' => Settings::at('2027-01-01 00:30:00')]])
        ->and(Settings::audit())->toHaveCount(1);
});
