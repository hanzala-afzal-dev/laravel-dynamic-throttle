<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Dashboard\Validation\ExemptionInput;
use Afzal\DynamicThrottle\Dashboard\Validation\SettingInput;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/*
| SPEC-018 AC-15 and AC-16: what the dashboard and its API accept for an
| exemption and for the settings. The settings take the CLI's durations and
| factors (SPEC-013). The app registers one custom scope, "tenant", and the
| clock stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    DynamicThrottle::scope('tenant', fn (): string => 'acme');
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    $this->rule = Throttling::rule();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The fields a validation refuses, by name, or [] when it accepts the input.
 *
 * @return list<string>
 */
function refused(Closure $validate): array
{
    try {
        $validate();
    } catch (ValidationException $exception) {
        return array_keys($exception->errors());
    }

    return [];
}

it('AC-15: accepts each valid exemption, and the exemption it saves compiles', function (array $input): void {
    $exemption = Exemption::create(app(ExemptionInput::class)->validate($input));

    expect(array_column(Exemptions::stored()['exemptions'], 'id'))->toContain((string) $exemption->id);
})->with([
    'an IPv4 range' => [['subject' => 'ip', 'value' => '10.0.0.0/8']],
    'an IPv6 range' => [['subject' => 'ip', 'value' => '2001:db8::/32']],
    'one address' => [['subject' => 'ip', 'value' => '203.0.113.7']],
    'a user' => [['subject' => 'user', 'value' => '42']],
    'a custom value' => [['subject' => 'custom', 'subject_parameter' => 'tenant', 'value' => 'acme']],
    'a reason of 255 characters, and an expiry' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'reason' => str_repeat('r', 255), 'expires_at' => '2027-01-16 12:00:00']],
]);

it('AC-15: accepts an exemption from one rule that exists', function (): void {
    $attributes = app(ExemptionInput::class)->validate(['subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => $this->rule->id]);

    expect($attributes['rule_id'])->toBe($this->rule->id);
});

it('review: accepts a rule id as text, as a form sends it', function (): void {
    $attributes = app(ExemptionInput::class)->validate(['subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => (string) $this->rule->id]);

    expect((int) $attributes['rule_id'])->toBe($this->rule->id);
});

it('AC-15: refuses each invalid exemption, naming its field', function (array $input, string $field): void {
    expect(refused(fn (): array => app(ExemptionInput::class)->validate($input)))->toBe([$field]);
})->with([
    'a /33 range' => [['subject' => 'ip', 'value' => '10.0.0.0/33'], 'value'],
    'not an address' => [['subject' => 'ip', 'value' => 'nope'], 'value'],
    'an unknown subject' => [['subject' => 'planet', 'value' => 'x'], 'subject'],
    'a rule that does not exist' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => 999], 'rule_id'],
    'an expiry in the past' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'expires_at' => '2027-01-15 11:59:59'], 'expires_at'],
    'a reason of 256 characters' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'reason' => str_repeat('r', 256)], 'reason'],
    'a custom scope the app did not register' => [['subject' => 'custom', 'subject_parameter' => 'planet', 'value' => 'x'], 'subject_parameter'],
    'a user id with a terminal escape' => [['subject' => 'user', 'value' => "42\e[2K"], 'value'],
    'a rule id as text that does not exist' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => '999'], 'rule_id'],
    'a rule id of true' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'rule_id' => true], 'rule_id'],
    'a reason with a right-to-left override' => [['subject' => 'ip', 'value' => '10.0.0.0/8', 'reason' => "Partner\u{202E}"], 'reason'],
]);

it('AC-16: takes bypass on and off, for the CLI\'s durations, an hour by default, or forever', function (array $input, bool $on, ?string $until): void {
    $setting = app(SettingInput::class)->bypass($input);

    expect([$setting['on'], $setting['until']?->clone()->setTimezone('UTC')->format('Y-m-d H:i:s')])->toBe([$on, $until]);
})->with([
    'on for 90s' => [['state' => 'on', 'for' => '90s'], true, '2027-01-15 12:01:30'],
    'on for 1d' => [['state' => 'on', 'for' => '1d'], true, '2027-01-16 12:00:00'],
    'on, an hour by default' => [['state' => 'on'], true, '2027-01-15 13:00:00'],
    'on forever' => [['state' => 'on', 'forever' => true], true, null],
    'off' => [['state' => 'off'], false, null],
]);

it('AC-16: refuses what the CLI refuses for bypass', function (array $input, string $field): void {
    expect(refused(fn (): array => app(SettingInput::class)->bypass($input)))->toBe([$field]);
})->with([
    'for 366d' => [['state' => 'on', 'for' => '366d'], 'for'],
    'for and forever together' => [['state' => 'on', 'for' => '1d', 'forever' => true], 'for'],
    'off for an hour' => [['state' => 'off', 'for' => '1h'], 'for'],
    'an unknown state' => [['state' => 'maybe'], 'state'],
]);

it('AC-16: takes the CLI\'s factors, or off', function (array $input, ?int $percent, ?string $until): void {
    $setting = app(SettingInput::class)->scale($input);

    expect([$setting['percent'], $setting['until']?->clone()->setTimezone('UTC')->format('Y-m-d H:i:s')])->toBe([$percent, $until]);
})->with([
    '0.1' => [['factor' => '0.1'], 10, '2027-01-15 13:00:00'],
    '10 for 2h' => [['factor' => '10', 'for' => '2h'], 1_000, '2027-01-15 14:00:00'],
    '1.5 forever' => [['factor' => '1.5', 'forever' => true], 150, null],
    'off' => [['factor' => 'off'], null, null],
]);

it('AC-16: refuses the factors the CLI refuses', function (string $factor): void {
    expect(refused(fn (): array => app(SettingInput::class)->scale(['factor' => $factor])))->toBe(['factor']);
})->with(['0.05', '1.234', '11', 'two', '']);
