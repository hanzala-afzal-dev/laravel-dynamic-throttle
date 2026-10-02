<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(RuleIndex::class);

/*
| SPEC-028: L1 stores the index with the rules it was built from (ADR-0023, as
| amended). Its parts come back as plain values, so they are checked: an index
| that disagreed with its rules would offer the wrong ones.
*/

/**
 * @return list<CompiledRule>
 */
function indexedRules(): array
{
    return array_map(fn (array $rule): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule([
        'id' => $rule[0], 'method' => $rule[1], 'pattern' => $rule[2],
    ])), [
        ['1', '*', 'api/*'],
        ['2', 'GET', '/api/users/*'],
        ['3', 'POST', '*/export'],
        ['4', 'GET', '/'],
        ['5', '*', '2024/*'],
    ]);
}

/**
 * The parts of an index of two rules: "api/*" for GET, and "*" for every method.
 *
 * @param  array<string, mixed>  $replace
 * @return array<string, mixed>
 */
function indexParts(array $replace = []): array
{
    return [...['literal' => ['GET' => ['api' => [0]]], 'wildcard' => ['*' => [1]], 'patterns' => ['api/*', '*']], ...$replace];
}

it('SPEC-028: gives its parts as plain values, and comes back from them unchanged', function (): void {
    $index = RuleIndex::of(indexedRules());

    $parts = $index->toArray();

    // "2024" is a numeric first segment, which PHP keys as an integer.
    expect($parts)->toBe([
        'literal' => ['*' => ['api' => [0], 2024 => [4]], 'GET' => ['api' => [1], '' => [3]]],
        'wildcard' => ['POST' => [2]],
        'patterns' => ['api/*', 'api/users/*', '*/export', '/', '2024/*'],
    ])
        ->and(RuleIndex::fromArray($parts, 5))->toEqual($index);
});

it('SPEC-028: offers the same candidates once it comes back', function (): void {
    $index = RuleIndex::of(indexedRules());
    $restored = RuleIndex::fromArray($index->toArray(), 5);

    foreach ([['GET', 'api/users/42'], ['HEAD', '2024/report'], ['POST', 'x/export'], ['DELETE', ''], ['GET', 'web']] as [$method, $path]) {
        expect($restored->candidates($method, $path))->toBe($index->candidates($method, $path));
    }
});

it('SPEC-028: comes back from the parts of an index of no rules', function (): void {
    expect(RuleIndex::fromArray(RuleIndex::of([])->toArray(), 0))->toEqual(RuleIndex::of([]));
});

it('SPEC-028: refuses parts that are not an index of the rules', function (array $parts, int $count, string $message): void {
    expect(fn (): RuleIndex => RuleIndex::fromArray($parts, $count))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'a missing part' => [array_diff_key(indexParts(), ['wildcard' => true]), 2, 'The index must have exactly the parts literal, wildcard and patterns.'],
    'a part too many' => [indexParts(['extra' => []]), 2, 'The index must have exactly the parts literal, wildcard and patterns.'],
    'patterns that are not a list' => [indexParts(['patterns' => ['a' => 'api/*', 'b' => '*']]), 2, 'The index\'s patterns must be a list of 2 strings, one for each rule.'],
    'a pattern that is not a string' => [indexParts(['patterns' => ['api/*', 7]]), 2, 'The index\'s patterns must be a list of 2 strings, one for each rule.'],
    'a pattern for a rule it does not have' => [indexParts(['patterns' => ['api/*', '*', 'web/*']]), 2, 'The index\'s patterns must be a list of 2 strings, one for each rule.'],
    'a literal part that is not a map' => [indexParts(['literal' => 'GET api']), 2, 'The index\'s literal part must map each method to segments, and each segment to positions.'],
    'a method without segments' => [indexParts(['literal' => ['GET' => [0]]]), 2, 'The index\'s literal part must map each method to segments, and each segment to positions.'],
    'a wildcard part that is not a map' => [indexParts(['wildcard' => '*']), 2, 'The index\'s wildcard part must map each method to positions.'],
    'a wildcard part keyed by position' => [indexParts(['wildcard' => [[1]]]), 2, 'The index has a method it does not know: "0".'],
    'wildcard positions that are not a list' => [indexParts(['wildcard' => ['*' => 1]]), 2, 'The index\'s positions must be lists of integers.'],
    'an unknown method' => [indexParts(['literal' => ['FETCH' => ['api' => [0]]]]), 2, 'The index has a method it does not know: "FETCH".'],
    'a lowercase method' => [indexParts(['literal' => ['get' => ['api' => [0]]]]), 2, 'The index has a method it does not know: "get".'],
    'a position that is not an integer' => [indexParts(['literal' => ['GET' => ['api' => ['0']]]]), 2, 'The index\'s positions must be lists of integers.'],
    'positions that are not a list' => [indexParts(['literal' => ['GET' => ['api' => [1 => 0]]]]), 2, 'The index\'s positions must be lists of integers.'],
    'a position past its rules' => [indexParts(['wildcard' => ['*' => [2]]]), 2, 'The index\'s position 2 is not one of its 2 rules.'],
    'a negative position' => [indexParts(['wildcard' => ['*' => [-1]]]), 2, 'The index\'s position -1 is not one of its 2 rules.'],
    'a rule offered twice' => [indexParts(['wildcard' => ['*' => [0, 1]]]), 2, 'The index offers rule 0 more than once.'],
    'a rule offered for two methods' => [indexParts(['wildcard' => ['*' => [1], 'POST' => [1]]]), 2, 'The index offers rule 1 more than once.'],
    'a rule it never offers' => [indexParts(['wildcard' => ['*' => []]]), 2, 'The index offers 1 of its 2 rules.'],
]);
