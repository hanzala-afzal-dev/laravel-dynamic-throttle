<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Dashboard\DashboardRoutes;
use Afzal\DynamicThrottle\Dashboard\Validation\Confirmation;
use Afzal\DynamicThrottle\Dashboard\Validation\ConfirmationRequired;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;

/*
| SPEC-018 AC-17 and AC-18: a dangerous change needs "confirm": true, in every
| environment, and is refused with a 409 that says what it does. The clock
| stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    $this->enforcing = Throttling::rule(['name' => 'API']);
    $this->dryRun = Throttling::rule(['name' => 'Audit', 'mode' => RuleMode::DryRun]);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The message of the ConfirmationRequired a call throws, or null when it throws none.
 */
function confirmationMessage(Closure $call): ?string
{
    try {
        $call();
    } catch (ConfirmationRequired $exception) {
        return $exception->getStatusCode() === 409 ? $exception->getMessage() : "status {$exception->getStatusCode()}";
    }

    return null;
}

it('AC-17: asks to confirm disabling or deleting an enforcing rule, bypass on, and a factor below 1, and passes them confirmed', function (Closure $change): void {
    expect(confirmationMessage(fn () => $change($this, [])))->not->toBeNull()
        ->and(confirmationMessage(fn () => $change($this, ['confirm' => true])))->toBeNull();
})->with([
    'disabling an enforcing rule' => [fn ($test, array $input) => Confirmation::ruleEnabled($test->enforcing, false, $input)],
    'deleting an enforcing rule' => [fn ($test, array $input) => Confirmation::ruleDeleted($test->enforcing, $input)],
    'bypass on' => [fn ($test, array $input) => Confirmation::bypass(true, Carbon::parse('2027-01-15 12:30:00', 'UTC'), $input)],
    'a factor below 1' => [fn ($test, array $input) => Confirmation::scale(50, null, $input)],
]);

it('AC-17: needs no confirmation to disable a dry-run rule, enable a rule, end bypass, or set a factor of 1 or above', function (Closure $change): void {
    expect(confirmationMessage(fn () => $change($this)))->toBeNull();
})->with([
    'disabling a dry-run rule' => [fn ($test) => Confirmation::ruleEnabled($test->dryRun, false, [])],
    'deleting a dry-run rule' => [fn ($test) => Confirmation::ruleDeleted($test->dryRun, [])],
    'enabling a rule' => [fn ($test) => Confirmation::ruleEnabled($test->enforcing, true, [])],
    'bypass off' => [fn ($test) => Confirmation::bypass(false, null, [])],
    'a factor of 1' => [fn ($test) => Confirmation::scale(100, null, [])],
    'a factor of 2' => [fn ($test) => Confirmation::scale(200, null, [])],
    'scaling off' => [fn ($test) => Confirmation::scale(null, null, [])],
]);

it('AC-18: says what each dangerous change does', function (Closure $change, string $message): void {
    expect(confirmationMessage(fn () => $change($this)))->toBe($message);
})->with([
    'disabling' => [fn ($test) => Confirmation::ruleEnabled($test->enforcing, false, []), 'Rule 1 (API) stops limiting requests until it is enabled again.'],
    'deleting' => [fn ($test) => Confirmation::ruleDeleted($test->enforcing, []), 'Rule 1 (API) is deleted, and stops limiting requests.'],
    'bypass until' => [fn () => Confirmation::bypass(true, Carbon::parse('2027-01-15 15:30:00', 'UTC'), []), 'Bypass lifts every limit until 2027-01-15 15:30 UTC.'],
    'bypass forever' => [fn () => Confirmation::bypass(true, null, []), 'Bypass lifts every limit, with no end time.'],
    'a factor until' => [fn () => Confirmation::scale(50, Carbon::parse('2027-01-15 14:00:00', 'UTC'), []), 'Every limit is multiplied by 0.5 until 2027-01-15 14:00 UTC, so clients are limited sooner.'],
    'a factor forever' => [fn () => Confirmation::scale(25, null, []), 'Every limit is multiplied by 0.25, with no end time, so clients are limited sooner.'],
]);

it('AC-17: answers a dashboard request without confirm with a 409 JSON message, in every environment, and lets a confirmed one through', function (string $environment): void {
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
    Dashboard::environment($environment);
    Dashboard::gates(null, fn (): bool => true);
    Route::middleware(DashboardRoutes::middleware('write'))->post('dynamic-throttle/api/test-bypass', function (Request $request): array {
        Confirmation::bypass(true, null, $request->all());

        return ['bypassed' => true];
    });

    $refused = $this->actingAs(new TestUser(['id' => 1]))->postJson('/dynamic-throttle/api/test-bypass');
    $confirmed = $this->actingAs(new TestUser(['id' => 1]))->postJson('/dynamic-throttle/api/test-bypass', ['confirm' => true]);

    expect([$refused->status(), $refused->json('message')])->toBe([409, 'Bypass lifts every limit, with no end time.'])
        ->and($confirmed->json())->toBe(['bypassed' => true]);
})->with(['local', 'production']);
