<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ManifestSnapshot::class);

/*
| SPEC-028: what L1 holds of a manifest. The APCu tests (tests/Apcu) cover
| storing it; these run in every suite.
*/

it('SPEC-028: turns a manifest into a snapshot and back, its state included', function (bool $stale): void {
    $manifest = ManifestCodec::decode(ManifestFixtures::json(7, [[]], [[]]));
    $manifest = $stale ? $manifest->stale() : $manifest;

    $snapshot = ManifestSnapshot::of($manifest, FakeClock::T0 * 1_000_000);

    expect([$snapshot->version, $snapshot->state, $snapshot->readAt])->toBe([7, $manifest->state, FakeClock::T0 * 1_000_000])
        ->and($snapshot->manifest())->toEqual($manifest);
})->with([
    'fresh' => [false],
    'stale' => [true],
]);

it('SPEC-028 AC-11: holds the compiled rules and exemptions, never the index or the exemption groups', function (): void {
    $manifest = ManifestCodec::decode(ManifestFixtures::json(7, [[]], [[]]));

    $snapshot = ManifestSnapshot::of($manifest, FakeClock::T0 * 1_000_000);

    expect($snapshot->rules)->toBe($manifest->rules)
        ->and($snapshot->exemptions)->toBe($manifest->exemptions->all())
        ->and(array_keys(get_object_vars($snapshot)))->toBe(['version', 'rules', 'exemptions', 'state', 'readAt']);
});
