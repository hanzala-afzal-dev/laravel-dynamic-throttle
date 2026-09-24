<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

/*
| SPEC-006: the manifest's JSON. Decoding is strict, because a manifest that
| decodes wrongly would apply the wrong limits (AC-16).
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

it('AC-2: writes exactly the rule fields requests need', function (): void {
    /** @var array{format: int, version: int, rules: list<array<string, mixed>>} $data */
    $data = json_decode(ManifestFixtures::json(1), true, flags: JSON_THROW_ON_ERROR);

    expect(array_keys($data))->toBe(['format', 'version', 'rules'])
        ->and($data['format'])->toBe(1)
        ->and(array_keys($data['rules'][0]))->toBe(array_keys(ManifestFixtures::rule()));
});

it('AC-16: rejects anything but a manifest of the current format', function (string $json): void {
    expect(fn (): Manifest => ManifestCodec::decode($json))->toThrow(InvalidManifest::class);
})->with(function (): iterable {
    $manifest = fn (array $data): string => json_encode($data, JSON_THROW_ON_ERROR);
    $withRule = fn (array $rule): string => $manifest(['format' => 1, 'version' => 1, 'rules' => [$rule]]);
    $rule = ManifestFixtures::rule();

    yield 'not JSON' => ['{'];
    yield 'not an object' => ['[1, 2]'];
    yield 'another format' => [$manifest(['format' => 2, 'version' => 1, 'rules' => []])];
    yield 'no format' => [$manifest(['version' => 1, 'rules' => []])];
    yield 'no version' => [$manifest(['format' => 1, 'rules' => []])];
    yield 'a version below 1' => [$manifest(['format' => 1, 'version' => 0, 'rules' => []])];
    yield 'a string version' => [$manifest(['format' => 1, 'version' => '5', 'rules' => []])];
    yield 'rules that are not a list' => [$manifest(['format' => 1, 'version' => 1, 'rules' => ['a' => $rule]])];
    yield 'an extra top-level key' => [$manifest(['format' => 1, 'version' => 1, 'rules' => [], 'extra' => true])];
    yield 'a rule that is not an object' => [$manifest(['format' => 1, 'version' => 1, 'rules' => ['rule']])];
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
