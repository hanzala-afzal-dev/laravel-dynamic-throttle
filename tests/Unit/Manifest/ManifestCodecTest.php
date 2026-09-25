<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\ExemptionSubject;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

/*
| SPEC-006: the manifest's JSON. Decoding is strict, because a manifest that
| decodes wrongly would apply the wrong limits (AC-16). SPEC-009 AC-8 and
| AC-12 add the exemptions, in format 2.
*/

it('encodes and decodes a manifest', function (): void {
    $override = ['message' => 'Slow down'];
    $manifest = ManifestCodec::decode(ManifestFixtures::json(42, [[
        'id' => '7', 'method' => 'POST', 'scope' => 'header', 'scope_parameter' => 'X-Api-Key',
        'mode' => 'dry_run', 'on_failure' => 'closed', 'on_missing_identity' => 'skip', 'response_override' => $override,
    ]]));
    $rule = $manifest->rules[0];

    expect($manifest->version)->toBe(42)
        ->and($manifest->state)->toBe(ManifestState::Fresh)
        ->and($manifest->rules)->toHaveCount(1)
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

it('AC-2 and SPEC-009 AC-8: writes format 2, with exactly the rule and exemption fields requests need', function (): void {
    /** @var array{format: int, version: int, rules: list<array<string, mixed>>, exemptions: list<array<string, mixed>>} $data */
    $data = json_decode(ManifestFixtures::json(1, [[]], [[]]), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['format', 'version', 'rules', 'exemptions'])
        ->and($data['format'])->toBe(2)
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
    $withRule = fn (array $rule): string => $manifest(['format' => 2, 'version' => 1, 'rules' => [$rule], 'exemptions' => []]);
    $rule = ManifestFixtures::rule();

    yield 'not JSON' => ['{'];
    yield 'not an object' => ['[1, 2]'];
    yield 'format 1, from before exemptions (SPEC-009 AC-12)' => [$manifest(['format' => 1, 'version' => 1, 'rules' => []])];
    yield 'format 1 with exemptions' => [$manifest(['format' => 1, 'version' => 1, 'rules' => [], 'exemptions' => []])];
    yield 'a later format' => [$manifest(['format' => 3, 'version' => 1, 'rules' => [], 'exemptions' => []])];
    yield 'no format' => [$manifest(['version' => 1, 'rules' => [], 'exemptions' => []])];
    yield 'no version' => [$manifest(['format' => 2, 'rules' => [], 'exemptions' => []])];
    yield 'a version below 1' => [$manifest(['format' => 2, 'version' => 0, 'rules' => [], 'exemptions' => []])];
    yield 'a string version' => [$manifest(['format' => 2, 'version' => '5', 'rules' => [], 'exemptions' => []])];
    yield 'rules that are not a list' => [$manifest(['format' => 2, 'version' => 1, 'rules' => ['a' => $rule], 'exemptions' => []])];
    yield 'an extra top-level key' => [$manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => [], 'extra' => true])];
    yield 'a rule that is not an object' => [$manifest(['format' => 2, 'version' => 1, 'rules' => ['rule'], 'exemptions' => []])];
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
    $withExemption = fn (array $exemption): string => $manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => [$exemption]]);
    $exemption = ManifestFixtures::exemption();

    yield 'no exemptions key' => [$manifest(['format' => 2, 'version' => 1, 'rules' => []])];
    yield 'exemptions that are not a list' => [$manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => ['a' => $exemption]])];
    yield 'an exemption that is not an object' => [$manifest(['format' => 2, 'version' => 1, 'rules' => [], 'exemptions' => ['10.0.0.0/8']])];
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
