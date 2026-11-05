<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestExemptions;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ManifestExemptions::class);

/*
| SPEC-028 AC-16 to AC-19, as amended for #194 and #195: a manifest's exemptions
| by position. Built by the compiler or decode(), they are all built. From L1 or
| the store's read path, each one is built from its fields the first time it is
| read, then kept, so a request builds only the exemptions its checks reach.
*/

it('SPEC-028 AC-16: gives the exemptions it was built with, by position, in full, and their count', function (): void {
    $exemptions = [
        CompiledExemption::fromArray(ManifestFixtures::exemption()),
        CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => '2', 'match' => '198.51.100.0/24'])),
    ];
    $list = ManifestExemptions::of($exemptions);

    expect([$list->get(1), $list->all(), $list->count()])->toBe([$exemptions[1], $exemptions, 2]);
});

it('SPEC-028 AC-16: builds an exemption from its fields only when it is read, then keeps it', function (): void {
    $list = ManifestExemptions::fromArrays([ManifestFixtures::exemption(), ManifestFixtures::exemption(['id' => 'NOT AN ID'])]);

    $first = $list->get(0);

    expect($first)->toEqual(CompiledExemption::fromArray(ManifestFixtures::exemption()))
        ->and($list->get(0))->toBe($first)
        ->and(fn () => $list->get(1))->toThrow(InvalidManifest::class, 'An exemption is invalid: id must be');
});

it('SPEC-028 AC-16: builds every exemption from its fields, in order, when all are read', function (): void {
    $fields = [ManifestFixtures::exemption(), ManifestFixtures::exemption(['id' => '2', 'subject' => 'user', 'match' => str_repeat('a', 64)])];

    expect(ManifestExemptions::fromArrays($fields)->all())->toEqual(array_map(CompiledExemption::fromArray(...), $fields));
});

it('SPEC-028 AC-19: counts the exemptions it holds as fields, and gives the fields back, without building them', function (): void {
    $fields = [ManifestFixtures::exemption(['id' => 'NOT AN ID']), ManifestFixtures::exemption(['id' => '2'])];
    $list = ManifestExemptions::fromArrays($fields);

    expect([$list->count(), $list->arrays()])->toBe([2, $fields]);
});

it('SPEC-028 AC-16: gives the fields of the exemptions it was built with, as the manifest writes them', function (): void {
    $exemption = CompiledExemption::fromArray(ManifestFixtures::exemption(['id' => '4']));

    expect(ManifestExemptions::of([$exemption])->arrays())->toBe([$exemption->toArray()]);
});

it('SPEC-028 AC-16: has no exemption past its last', function (string $from): void {
    $list = $from === 'fields'
        ? ManifestExemptions::fromArrays([ManifestFixtures::exemption()])
        : ManifestExemptions::of([CompiledExemption::fromArray(ManifestFixtures::exemption())]);

    expect(fn () => $list->get(1))->toThrow(OutOfRangeException::class, 'The manifest has no exemption at position 1.');
})->with(['built' => ['built'], 'fields' => ['fields']]);
