<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

/*
| SPEC-006: the manifest's JSON. Decoding is strict, because a manifest that
| decodes wrongly would apply the wrong limits (AC-16). SPEC-009 AC-8 and
| AC-12 add the exemptions, in format 2. SPEC-013 AC-24 adds the runtime
| settings, in format 3.
*/

it('encodes and decodes a manifest', function (): void {
    $override = ['message' => 'Slow down'];
    $manifest = ManifestCodec::decode(ManifestFixtures::json(42, [[
        'id' => '7', 'method' => 'POST', 'scope' => 'header', 'scope_parameter' => 'X-Api-Key',
        'mode' => 'dry_run', 'on_failure' => 'closed', 'on_missing_identity' => 'skip', 'response_override' => $override,
    ]]));
    $rule = $manifest->rule(0);

    expect($manifest->version)->toBe(42)
        ->and($manifest->state)->toBe(ManifestState::Fresh)
        ->and($manifest->rules())->toHaveCount(1)
        ->and([$rule->id, $rule->name, $rule->pattern, $rule->scopeParameter])->toBe(['7', 'API', 'api/*', 'X-Api-Key'])
        ->and([$rule->method, $rule->scope, $rule->mode, $rule->onFailure, $rule->onMissingIdentity])
        ->toBe([RequestMethod::Post, Scope::Header, RuleMode::DryRun, FailurePolicy::Closed, MissingIdentity::Skip])
        ->and([$rule->maxAttempts, $rule->windowSeconds, $rule->priority])->toBe([60, 60, 100])
        ->and($rule->responseOverride)->toBe($override);
});

it('SPEC-009 AC-8: decodes the exemptions, in order, into the manifest\'s set', function (): void {
    $manifest = ManifestCodec::decode(ManifestFixtures::json(42, [[]], [
        ['id' => '3', 'rule' => '1', 'match' => '2001:db8::/32', 'expires_at' => 1_800_000_060],
        ['id' => '5', 'subject' => 'custom', 'parameter' => 'tenant', 'match' => hash('sha256', 'acme')],
    ]));
    $exemptions = $manifest->exemptions->all();

    expect($exemptions)->toHaveCount(2)
        ->and(array_map(fn (CompiledExemption $exemption): string => $exemption->id, $exemptions))->toBe(['3', '5'])
        ->and([$exemptions[0]->rule, $exemptions[0]->subject, $exemptions[0]->match, $exemptions[0]->expiresAt])
        ->toBe(['1', ExemptionSubject::Ip, '2001:db8::/32', 1_800_000_060])
        ->and([$exemptions[1]->rule, $exemptions[1]->subject, $exemptions[1]->parameter])->toBe([null, ExemptionSubject::Custom, 'tenant']);
});

it('AC-2, SPEC-009 AC-8 and SPEC-013 AC-24: writes format 3, with exactly the rule, exemption and settings fields requests need', function (): void {
    /** @var array{format: int, version: int, rules: list<array<string, mixed>>, exemptions: list<array<string, mixed>>, settings: mixed} $data */
    $data = json_decode(ManifestFixtures::json(1, [[]], [[]]), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['format', 'version', 'rules', 'exemptions', 'settings'])
        ->and($data['format'])->toBe(3)
        ->and(array_keys($data['rules'][0]))->toBe(array_keys(ManifestFixtures::rule()))
        ->and($data['exemptions'])->toBe([ManifestFixtures::exemption()]);
});

it('SPEC-009 AC-8: writes an empty list when there are no exemptions', function (): void {
    /** @var array{exemptions: mixed} $data */
    $data = json_decode(ManifestFixtures::json(1), true, flags: JSON_THROW_ON_ERROR);

    expect($data['exemptions'])->toBe([])
        ->and(ManifestCodec::decode(ManifestFixtures::json(1))->exemptions->all())->toBe([]);
});

it('AC-16: rejects anything but a manifest of the current format', function (string $json): void {
    expect(fn (): Manifest => ManifestCodec::decode($json))->toThrow(InvalidManifest::class);
})->with(function (): iterable {
    $manifest = fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
    $settings = new stdClass;
    $withRule = fn (array $rule): string => $manifest(['format' => 3, 'version' => 1, 'rules' => [$rule], 'exemptions' => [], 'settings' => $settings]);
    $rule = ManifestFixtures::rule();

    yield 'not JSON' => ['{'];
    yield 'not an object' => ['[1, 2]'];
    yield 'format 1, from before exemptions (SPEC-009 AC-12)' => [$manifest(['format' => 1, 'version' => 1, 'rules' => []])];
    yield 'format 1 with exemptions' => [$manifest(['format' => 1, 'version' => 1, 'rules' => [], 'exemptions' => []])];
    yield 'format 2, from before the settings (SPEC-013 AC-24)' => [$manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => []])];
    yield 'format 2 with settings' => [$manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'a later format' => [$manifest(['format' => 4, 'version' => 1, 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'no format' => [$manifest(['version' => 1, 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'no version' => [$manifest(['format' => 3, 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'a version below 1' => [$manifest(['format' => 3, 'version' => 0, 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'a string version' => [$manifest(['format' => 3, 'version' => '5', 'rules' => [], 'exemptions' => [], 'settings' => $settings])];
    yield 'rules that are not a list' => [$manifest(['format' => 3, 'version' => 1, 'rules' => ['a' => $rule], 'exemptions' => [], 'settings' => $settings])];
    yield 'an extra top-level key' => [$manifest(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => [], 'settings' => $settings, 'extra' => true])];
    yield 'a rule that is not an object' => [$manifest(['format' => 3, 'version' => 1, 'rules' => ['rule'], 'exemptions' => [], 'settings' => $settings])];
    yield 'a rule without a field' => [$withRule(array_diff_key($rule, ['priority' => true]))];
    yield 'a rule with an extra field' => [$withRule([...$rule, 'description' => 'x'])];
    yield 'an unknown method' => [$withRule([...$rule, 'method' => 'FETCH'])];
    yield 'an unknown scope' => [$withRule([...$rule, 'scope' => 'planet'])];
    yield 'an unknown mode' => [$withRule([...$rule, 'mode' => 'maybe'])];
    yield 'an unknown failure policy' => [$withRule([...$rule, 'on_failure' => 'sometimes'])];
    yield 'an unknown missing-identity policy' => [$withRule([...$rule, 'on_missing_identity' => 'guess'])];
    yield 'an integer id' => [$withRule([...$rule, 'id' => 1])];
    yield 'an id counters reject' => [$withRule([...$rule, 'id' => 'Rule 1'])];
    yield 'max 0' => [$withRule([...$rule, 'max_attempts' => 0])];
    yield 'window 0' => [$withRule([...$rule, 'window_seconds' => 0])];
    yield 'window 86,401' => [$withRule([...$rule, 'window_seconds' => 86_401])];
    yield 'a string max' => [$withRule([...$rule, 'max_attempts' => '60'])];
    yield 'an empty pattern' => [$withRule([...$rule, 'pattern' => ''])];
    yield 'a header rule without a parameter' => [$withRule([...$rule, 'scope' => 'header'])];
    yield 'a custom rule without a parameter' => [$withRule([...$rule, 'scope' => 'custom', 'scope_parameter' => ''])];
    yield 'a response override that is not an object' => [$withRule([...$rule, 'response_override' => 'Slow down'])];
});

it('SPEC-009 AC-12: rejects a manifest whose exemptions are not exactly as the compiler writes them', function (string $json): void {
    expect(fn (): Manifest => ManifestCodec::decode($json))->toThrow(InvalidManifest::class);
})->with(function (): iterable {
    $manifest = fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
    $settings = new stdClass;
    $withExemption = fn (array $exemption): string => $manifest(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => [$exemption], 'settings' => $settings]);
    $exemption = ManifestFixtures::exemption();

    yield 'no exemptions key' => [$manifest(['format' => 3, 'version' => 1, 'rules' => [], 'settings' => $settings])];
    yield 'exemptions that are not a list' => [$manifest(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => ['a' => $exemption], 'settings' => $settings])];
    yield 'an exemption that is not an object' => [$manifest(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => ['10.0.0.0/8'], 'settings' => $settings])];
    yield 'an exemption with an unknown field' => [$withExemption([...$exemption, 'reason' => 'Office'])];
    yield 'an exemption without a field' => [$withExemption(array_diff_key($exemption, ['expires_at' => true]))];
    yield 'an unknown subject' => [$withExemption([...$exemption, 'subject' => 'header'])];
    yield 'an integer id' => [$withExemption([...$exemption, 'id' => 1])];
    yield 'an integer rule' => [$withExemption([...$exemption, 'rule' => 1])];
    yield 'a string expiry' => [$withExemption([...$exemption, 'expires_at' => '1800000060'])];
    yield 'an ip match that is not a range' => [$withExemption([...$exemption, 'match' => 'not-an-ip'])];
    yield 'a user match that is not a hash' => [$withExemption([...$exemption, 'subject' => 'user', 'match' => 'App\\Models\\User:42'])];
    yield 'a custom exemption without a scope' => [$withExemption([...$exemption, 'subject' => 'custom', 'match' => hash('sha256', 'acme')])];
});

it('SPEC-028: encodes one rule as the manifest writes it, and decodes it back', function (): void {
    $rule = CompiledRule::fromArray(ManifestFixtures::rule(['id' => '7', 'pattern' => 'api/users/*', 'response_override' => ['message' => 'Slow down, café']]));

    $json = ManifestCodec::encodeRule($rule);

    expect($json)->toBe(json_encode($rule->toArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->and(ManifestCodec::decodeRule($json))->toEqual($rule);
});

it('SPEC-028: rejects a rule\'s JSON that is not a rule', function (string $json, string $message): void {
    expect(fn (): CompiledRule => ManifestCodec::decodeRule($json))->toThrow(InvalidManifest::class, $message);
})->with([
    'not JSON' => ['{"id"', 'A rule is not JSON: Syntax error'],
    'a list' => ['[1, 2]', 'A rule must be a JSON object.'],
    'a string' => ['"api/*"', 'A rule must be a JSON object.'],
    'an invalid rule' => [json_encode(ManifestFixtures::rule(['max_attempts' => 0]), JSON_THROW_ON_ERROR), 'A rule is invalid: max_attempts must be at least 1, got 0.'],
]);

it('SPEC-013 AC-24: writes the settings as an object, empty when none is on', function (): void {
    expect(ManifestFixtures::json(1))->toContain('"settings":{}')
        ->and(ManifestCodec::decode(ManifestFixtures::json(1))->settings)->toEqual(ManifestSettings::none());
});

it('SPEC-013 AC-24: encodes and decodes the settings', function (): void {
    $settings = new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0 + 60, scale: 150);

    $json = ManifestFixtures::json(1, settings: $settings);

    expect(json_decode($json, true, flags: JSON_THROW_ON_ERROR)['settings'] ?? null)->toBe($settings->toArray())
        ->and(ManifestCodec::decode($json)->settings)->toEqual($settings);
});

it('SPEC-013 AC-24: rejects a manifest whose settings are not settings', function (mixed $settings): void {
    $json = json_encode(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => [], 'settings' => $settings], JSON_THROW_ON_ERROR);

    expect(fn (): Manifest => ManifestCodec::decode($json))->toThrow(InvalidManifest::class, "The manifest's settings are invalid: ");
})->with([
    'a string' => ['bypass_all'],
    'a list' => [[['until' => null]]],
    'an unknown setting' => [['maintenance' => ['until' => null]]],
    'a factor below 0.1' => [['scale_factor' => ['percent' => 9, 'until' => null]]],
    'a factor above 10' => [['scale_factor' => ['percent' => 1_001, 'until' => null]]],
    'a factor with more than two decimals' => [['scale_factor' => ['percent' => 150.5, 'until' => null]]],
    'an end time that is not an integer' => [['bypass_all' => ['until' => '2027-01-01 00:30:00']]],
]);
