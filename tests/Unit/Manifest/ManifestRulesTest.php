<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRules;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ManifestRules::class);

/*
| SPEC-028: a manifest's rules by position. Decoded from the store, they are
| all built. From L1, each one is built from its JSON when it is first read
| (AC-14), so a PHP-FPM request builds only the rules it matches.
*/

function namedRule(string $id): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule(['id' => $id, 'pattern' => "{$id}/*"]));
}

it('SPEC-028: gives the rules it was built with, by position and in full', function (): void {
    $rules = [namedRule('a'), namedRule('b')];

    $built = ManifestRules::of($rules);

    expect($built->get(1))->toBe($rules[1])
        ->and($built->all())->toBe($rules);
});

it('AC-14: builds a rule from its JSON only when it is read, then keeps it', function (): void {
    $rules = ManifestRules::fromJson([ManifestCodec::encodeRule(namedRule('a')), '{"never": "read"}']);

    $first = $rules->get(0);

    expect($first)->toEqual(namedRule('a'))
        ->and($rules->get(0))->toBe($first)
        ->and(fn (): CompiledRule => $rules->get(1))->toThrow(InvalidManifest::class, 'A rule must have exactly the fields');
});

it('SPEC-028: builds every rule from its JSON, in order, when all are read', function (): void {
    $rules = ManifestRules::fromJson(array_map(fn (string $id): string => ManifestCodec::encodeRule(namedRule($id)), ['a', 'b', 'c']));
    $rules->get(2);

    expect(array_map(fn (CompiledRule $rule): string => $rule->id, $rules->all()))->toBe(['a', 'b', 'c']);
});

it('SPEC-028: gives each rule\'s JSON for L1, without building the rules it holds as JSON', function (): void {
    $json = [ManifestCodec::encodeRule(namedRule('a')), 'never built'];

    expect(ManifestRules::fromJson($json)->json())->toBe($json)
        ->and(ManifestRules::of([namedRule('a'), namedRule('b')])->json())
        ->toBe([ManifestCodec::encodeRule(namedRule('a')), ManifestCodec::encodeRule(namedRule('b'))]);
});

it('SPEC-028: refuses a position it holds no rule at', function (ManifestRules $rules): void {
    expect(fn (): CompiledRule => $rules->get(1))->toThrow(OutOfRangeException::class, 'The manifest has no rule at position 1.');
})->with([
    'built' => [fn (): ManifestRules => ManifestRules::of([namedRule('a')])],
    'as JSON' => [fn (): ManifestRules => ManifestRules::fromJson([ManifestCodec::encodeRule(namedRule('a'))])],
]);
