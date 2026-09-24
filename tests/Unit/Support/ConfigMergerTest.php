<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Support\ConfigMerger;

covers(ConfigMerger::class);

/*
| SPEC-005 AC-1 to AC-5 (ADR-0020 point 10): the app's config is merged over
| the package defaults. Maps merge key by key, lists are replaced whole.
*/

it('AC-1: merges maps key by key', function (): void {
    $merged = ConfigMerger::merge(['a' => 1, 'm' => ['x' => 1, 'y' => 2]], ['m' => ['x' => 9]]);

    expect($merged)->toBe(['a' => 1, 'm' => ['x' => 9, 'y' => 2]]);
});

it('AC-2: replaces a list as a whole, so removed entries stay removed', function (array $app, array $expected): void {
    expect(ConfigMerger::merge(['l' => ['p', 'q', 'r']], $app))->toBe($expected);
})->with([
    'shortened' => [['l' => ['p']], ['l' => ['p']]],
    'emptied' => [['l' => []], ['l' => []]],
]);

it('AC-3: keeps the defaults when the app gives an empty array for a map', function (): void {
    expect(ConfigMerger::merge(['m' => ['x' => 1]], ['m' => []]))->toBe(['m' => ['x' => 1]]);
});

it('AC-4: lets the app win and keeps keys the package does not know', function (array $app, array $expected): void {
    expect(ConfigMerger::merge(['a' => 1, 'm' => ['x' => 1]], $app))->toBe($expected);
})->with([
    'a scalar' => [['a' => 2], ['a' => 2, 'm' => ['x' => 1]]],
    'null' => [['a' => null], ['a' => null, 'm' => ['x' => 1]]],
    'a list where a map is expected' => [['m' => ['oops']], ['a' => 1, 'm' => ['oops']]],
    'an unknown key' => [['extra' => true], ['a' => 1, 'm' => ['x' => 1], 'extra' => true]],
]);

it('AC-5: merges at any depth', function (): void {
    $merged = ConfigMerger::merge(['a' => ['b' => ['c' => 1, 'd' => 2]]], ['a' => ['b' => ['c' => 3]]]);

    expect($merged)->toBe(['a' => ['b' => ['c' => 3, 'd' => 2]]]);
});
