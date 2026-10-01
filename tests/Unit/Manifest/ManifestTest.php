<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRules;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(Manifest::class);

/*
| SPEC-028: a manifest reads its rules by position, so one built from L1
| builds only the rules a request matches, with the index stored in L1
| (ADR-0023, as amended).
*/

function manifestRule(string $id, string $pattern): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule(['id' => $id, 'pattern' => $pattern]));
}

it('SPEC-028: reads its rules by position, and in full', function (): void {
    $rules = [manifestRule('1', 'api/*'), manifestRule('2', 'web/*')];

    $manifest = new Manifest(7, $rules);

    expect([$manifest->rule(0), $manifest->rule(1)])->toBe($rules)
        ->and($manifest->rules())->toBe($rules)
        ->and($manifest->index)->toEqual(RuleIndex::of($rules));
});

it('SPEC-028: uses an index stored with its rules, instead of building one', function (): void {
    $stored = RuleIndex::of([manifestRule('9', 'web/*')]);

    $manifest = new Manifest(7, [manifestRule('1', 'api/*')], index: $stored);

    expect($manifest->index)->toBe($stored);
});

it('SPEC-028: gives each rule\'s JSON, as L1 keeps it', function (): void {
    $rules = [manifestRule('1', 'api/*'), manifestRule('2', 'web/*')];

    expect((new Manifest(7, $rules))->rulesJson())->toBe(array_map(ManifestCodec::encodeRule(...), $rules));
});

it('SPEC-028: goes stale with the same rules, index and exemptions, and builds no rule to do it', function (): void {
    $json = [ManifestCodec::encodeRule(manifestRule('1', 'api/*')), 'never built'];
    $index = RuleIndex::of([manifestRule('1', 'api/*'), manifestRule('2', 'web/*')]);
    $exemptions = [CompiledExemption::fromArray(ManifestFixtures::exemption())];
    $manifest = new Manifest(7, ManifestRules::fromJson($json), $exemptions, ManifestState::Fresh, $index);

    $stale = $manifest->stale();

    expect([$stale->version, $stale->state, $stale->index, $stale->rulesJson()])->toBe([7, ManifestState::Stale, $index, $json])
        ->and($stale->exemptions->all())->toBe($exemptions)
        ->and($stale->rule(0))->toEqual(manifestRule('1', 'api/*'));
});

it('SPEC-028: is missing with no rules', function (): void {
    $manifest = Manifest::missing();

    expect([$manifest->version, $manifest->state, $manifest->rules(), $manifest->rulesJson()])->toBe([0, ManifestState::Missing, [], []]);
});
