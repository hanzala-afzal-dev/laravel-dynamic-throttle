<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Matching\MatchResult;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Random\Engine\Mt19937;
use Random\Randomizer;

covers(ManifestSnapshot::class);

/*
| SPEC-028: what L1 holds of a manifest, as plain values, which APCu copies
| without unserializing objects: the index built from the rules (ADR-0023, as
| amended), each rule's JSON, and the exemptions. SPEC-013 AC-24 adds the
| runtime settings. The APCu tests (tests/Apcu) cover storing it; these run in
| every suite.
*/

function snapshotManifest(): Manifest
{
    return ManifestCodec::decode(ManifestFixtures::json(7, [
        [],
        ['id' => '2', 'method' => 'GET', 'pattern' => 'web/*'],
    ], [
        [],
        ['id' => '2', 'rule' => '2', 'subject' => 'user', 'match' => str_repeat('a', 64), 'expires_at' => FakeClock::T0 + 60],
    ]));
}

/**
 * @return list<string> the ids of the rules a manifest matches for a request
 */
function snapshotMatches(Manifest $manifest, string $method, string $path): array
{
    return array_map(fn (CompiledRule $rule): string => $rule->id, (new RuleMatcher)->match($manifest, $method, $path)->rules);
}

it('SPEC-028: turns a manifest into a snapshot and back, its state included', function (bool $stale): void {
    $manifest = $stale ? snapshotManifest()->stale() : snapshotManifest();

    $snapshot = ManifestSnapshot::of($manifest, FakeClock::T0 * 1_000_000);
    $back = $snapshot->manifest();

    expect([$snapshot->version, $snapshot->state, $snapshot->readAt])->toBe([7, $manifest->state, FakeClock::T0 * 1_000_000])
        ->and([$back->version, $back->state])->toBe([7, $manifest->state])
        ->and([$back->rules(), $back->index, $back->exemptions])->toEqual([$manifest->rules(), $manifest->index, $manifest->exemptions]);
})->with([
    'fresh' => [false],
    'stale' => [true],
]);

it('AC-11: holds the index built from the rules, each rule\'s JSON and the exemptions, as plain values', function (): void {
    $manifest = snapshotManifest();

    $entry = ManifestSnapshot::of($manifest, FakeClock::T0 * 1_000_000)->toArray();

    expect($entry)->toBe([
        'version' => 7,
        'index' => RuleIndex::of($manifest->rules())->toArray(),
        'rules' => array_map(ManifestCodec::encodeRule(...), $manifest->rules()),
        'exemptions' => array_map(fn (CompiledExemption $exemption): array => $exemption->toArray(), $manifest->exemptions->all()),
        'settings' => [],
        'state' => 'fresh',
        'read_at' => FakeClock::T0 * 1_000_000,
    ]);
});

it('SPEC-028: comes back from its plain values unchanged', function (bool $stale): void {
    $snapshot = ManifestSnapshot::of($stale ? snapshotManifest()->stale() : snapshotManifest(), FakeClock::T0 * 1_000_000);

    expect(ManifestSnapshot::fromArray($snapshot->toArray()))->toEqual($snapshot);
})->with([
    'fresh' => [false],
    'stale' => [true],
]);

it('AC-9: is no snapshot without a snapshot\'s fields and types', function (mixed $entry): void {
    expect(ManifestSnapshot::fromArray($entry))->toBeNull();
})->with(function (): array {
    $entry = ManifestSnapshot::of(snapshotManifest(), FakeClock::T0 * 1_000_000)->toArray();

    return [
        'a string' => ['a manifest'],
        'a list' => [array_values($entry)],
        'an object' => [(object) $entry],
        'a missing field' => [array_diff_key($entry, ['exemptions' => true])],
        'a field too many' => [[...$entry, 'format' => 2]],
        'a version that is not an integer' => [[...$entry, 'version' => '7']],
        'a version below 1' => [[...$entry, 'version' => 0]],
        'an unknown state' => [[...$entry, 'state' => 'warm']],
        'the missing state, which L1 never holds' => [[...$entry, 'state' => 'missing']],
        'a read time that is not an integer' => [[...$entry, 'read_at' => 1.5]],
        'rules that are not a list' => [[...$entry, 'rules' => ['a' => $entry['rules'][0]]]],
        'a rule that is not a string' => [[...$entry, 'rules' => [ManifestFixtures::rule(), $entry['rules'][1]]]],
        'an index that is not an array' => [[...$entry, 'index' => 'api/*']],
        'exemptions that are not a list' => [[...$entry, 'exemptions' => ['a' => $entry['exemptions'][0]]]],
        'an exemption that is not an array' => [[...$entry, 'exemptions' => ['203.0.113.0/24']]],
    ];
});

it('AC-9: is no snapshot with an index or exemptions that cannot be built, so the fetch deletes the entry', function (array $replace): void {
    expect(ManifestSnapshot::fromArray([...ManifestSnapshot::of(snapshotManifest(), FakeClock::T0 * 1_000_000)->toArray(), ...$replace]))->toBeNull();
})->with([
    'an index that points past its rules' => [['index' => ['literal' => [], 'wildcard' => ['*' => [0, 1, 2]], 'patterns' => ['api/*', 'web/*']]]],
    'an index with a pattern for a rule it does not have' => [['index' => ['literal' => [], 'wildcard' => ['*' => [0, 1]], 'patterns' => ['api/*', 'web/*', 'x/*']]]],
    'an exemption that is not an exemption' => [['exemptions' => [ManifestFixtures::exemption(['match' => 'not a range'])]]],
    'settings that are not settings (SPEC-013 AC-24)' => [['settings' => ['scale_factor' => ['percent' => 9, 'until' => null]]]],
]);

it('SPEC-013 AC-24: keeps the manifest\'s settings, so a manifest from L1 applies them', function (): void {
    $settings = new ManifestSettings(bypass: true, bypassUntil: FakeClock::T0 + 60, scale: 200);
    $manifest = ManifestCodec::decode(ManifestFixtures::json(7, settings: $settings));

    $entry = ManifestSnapshot::of($manifest, FakeClock::T0 * 1_000_000)->toArray();

    expect($entry['settings'] ?? null)->toBe($settings->toArray())
        ->and(ManifestSnapshot::fromArray($entry)?->manifest()->settings)->toEqual($settings);
});

it('AC-11: builds a manifest that offers the same candidates and matches the same rules as the decoded one, on 2,000 seeded requests', function (): void {
    $random = new Randomizer(new Mt19937(20_261_001));
    $parts = ['api', 'web', 'v1', 'users', '42', '2024', '*', 'ap*', '*s'];
    $segments = fn (): string => implode('/', array_map(fn (): string => $parts[$random->getInt(0, count($parts) - 1)], range(1, $random->getInt(1, 3))));
    $methods = ['*', 'GET', 'HEAD', 'POST', 'DELETE'];
    $rules = [];

    foreach (range(1, 150) as $id) {
        $rules[] = [
            'id' => (string) $id,
            'method' => $methods[$random->getInt(0, count($methods) - 1)],
            'pattern' => match ($random->getInt(1, 10)) {
                1 => '/',
                2 => '*',
                3 => '/'.$segments(),
                default => $segments(),
            },
        ];
    }

    $decoded = ManifestCodec::decode(ManifestFixtures::json(7, $rules));
    $fromL1 = ManifestSnapshot::fromArray(ManifestSnapshot::of($decoded, FakeClock::T0 * 1_000_000)->toArray())?->manifest();

    expect($fromL1)->toBeInstanceOf(Manifest::class);

    foreach (range(1, 2_000) as $ignored) {
        $method = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'][$random->getInt(0, 4)];
        $path = str_replace('*', 'x', $segments());

        expect($fromL1?->index->candidates($method, $path))->toBe($decoded->index->candidates($method, $path))
            ->and(snapshotMatches($fromL1 ?? $decoded, $method, $path))->toBe(snapshotMatches($decoded, $method, $path));
    }
});

it('AC-14: builds only the rules a request matches, so a rule\'s JSON nobody matches is never read', function (): void {
    $entry = ManifestSnapshot::of(snapshotManifest(), FakeClock::T0 * 1_000_000)->toArray();
    // Something other than the package spoils the web rule's JSON.
    $entry['rules'][1] = '{"id": "2"';
    $manifest = ManifestSnapshot::fromArray($entry)?->manifest();

    expect($manifest)->toBeInstanceOf(Manifest::class)
        ->and(snapshotMatches($manifest ?? Manifest::missing(), 'GET', 'api/users'))->toBe(['1'])
        ->and(fn (): MatchResult => (new RuleMatcher)->match($manifest ?? Manifest::missing(), 'GET', 'web/home'))->toThrow(InvalidManifest::class, 'A rule is not JSON');
});
