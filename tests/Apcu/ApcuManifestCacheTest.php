<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\LocalManifests;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Afzal\DynamicThrottle\Tests\Support\UnreadableEntry;

covers(ApcuManifestCache::class);

/*
| SPEC-028: the manifest's entry in APCu, L1, where the PHP-FPM workers of a
| server share it. `composer test:apcu` runs this group with apc.enable_cli=1,
| and an APCu segment of 8 MB, so AC-10 can overflow it cheaply.
*/

beforeEach(function (): void {
    if (! ApcuManifestCache::available()) {
        $this->markTestSkipped('APCu is off. Run composer test:apcu.');
    }

    apcu_clear_cache();
});

it('AC-2: keeps a snapshot where the other processes of the server read it, its stale mark included', function (bool $stale): void {
    $snapshot = LocalManifests::snapshot(7, stale: $stale);

    LocalManifests::cache()->store($snapshot);

    expect(LocalManifests::cache()->fetch())->toEqual($snapshot);
})->with([
    'fresh' => [false],
    'stale' => [true],
]);

it('fetches nothing before a snapshot is stored', function (): void {
    expect(LocalManifests::cache()->fetch())->toBeNull();
});

it('replaces the entry with each snapshot it stores', function (): void {
    LocalManifests::cache()->store(LocalManifests::snapshot(7));
    LocalManifests::cache()->store(LocalManifests::snapshot(8));

    expect(LocalManifests::cache()->fetch()?->version)->toBe(8);
});

it('keeps an entry for a day, as the last known manifest (decision 6)', function (): void {
    LocalManifests::cache()->store(LocalManifests::snapshot(7));

    expect(apcu_key_info(LocalManifests::key())['ttl'] ?? null)->toBe(86_400);
});

it('AC-6: never reads the entry of another app of the server', function (): void {
    LocalManifests::cache(app: 'staging')->store(LocalManifests::snapshot(7));

    expect(LocalManifests::cache(app: 'production')->fetch())->toBeNull()
        ->and(LocalManifests::cache(app: 'staging')->fetch()?->version)->toBe(7);
});

it('AC-7: never reads an entry that another version of the package wrote', function (): void {
    LocalManifests::cache(version: 'v1')->store(LocalManifests::snapshot(7));

    expect(LocalManifests::cache(version: 'v2')->fetch())->toBeNull()
        ->and(LocalManifests::cache(version: 'v1')->fetch()?->version)->toBe(7);
});

it('AC-13: keys its entry with KeyBuilder, so apps with different prefixes never share it', function (): void {
    (new ApcuManifestCache(new KeyBuilder('one:'), 'app', 'v1'))->store(LocalManifests::snapshot(7));

    expect(apcu_exists((new KeyBuilder('one:'))->localManifest('app', 'v1')))->toBeTrue()
        ->and((new ApcuManifestCache(new KeyBuilder('two:'), 'app', 'v1'))->fetch())->toBeNull();
});

it('AC-9: ignores and deletes an entry that is not a snapshot, and throws nothing', function (mixed $entry): void {
    apcu_store(LocalManifests::key(), $entry);

    $fetched = LocalManifests::cache()->fetch();

    expect($fetched)->toBeNull()
        ->and(apcu_exists(LocalManifests::key()))->toBeFalse();
})->with([
    'a string' => ['{"format":2,"version":7,"rules":[],"exemptions":[]}'],
    'an array' => [['version' => 7, 'rules' => [], 'exemptions' => []]],
    'an object of another class' => [fn (): ArrayObject => new ArrayObject(['version' => 7])],
    'an object that throws when APCu reads it' => [fn (): UnreadableEntry => new UnreadableEntry],
    'a snapshot object, which L1 never stores' => [fn (): ManifestSnapshot => LocalManifests::snapshot(7)],
    'an entry whose rules are not JSON strings' => [fn (): array => [...LocalManifests::snapshot(7)->toArray(), 'rules' => [ManifestFixtures::rule()]]],
    'an entry with an unknown state' => [fn (): array => [...LocalManifests::snapshot(7)->toArray(), 'state' => 'warm']],
]);

it('AC-10: keeps nothing, and throws nothing, when APCu cannot store the entry', function (ManifestSnapshot $snapshot): void {
    LocalManifests::cache()->store($snapshot);

    expect(apcu_exists(LocalManifests::key()))->toBeFalse();
})->with([
    // APCu refuses an entry larger than its memory: apcu_store() returns false.
    'larger than APCu\'s memory' => [fn (): ManifestSnapshot => new ManifestSnapshot(
        version: 7,
        index: LocalManifests::snapshot(7)->index,
        rules: [str_repeat('x', (int) apcu_sma_info(true)['seg_size'])],
        exemptions: [],
        state: ManifestState::Fresh,
        readAt: FakeClock::T0 * 1_000_000,
    )],
    // apcu_store() throws on a value PHP cannot serialize, which only a snapshot built by hand holds.
    'that APCu cannot serialize' => [fn (): ManifestSnapshot => new ManifestSnapshot(
        version: 7,
        index: LocalManifests::snapshot(7)->index,
        rules: LocalManifests::snapshot(7)->rules,
        exemptions: [['id' => fn (): string => '1']],
        state: ManifestState::Fresh,
        readAt: FakeClock::T0 * 1_000_000,
    )],
]);

it('AC-11: keeps the snapshot as plain values, which APCu copies without unserializing objects', function (): void {
    $snapshot = LocalManifests::snapshot(7);

    LocalManifests::cache()->store($snapshot);

    expect(apcu_fetch(LocalManifests::key()))->toBe($snapshot->toArray());
});
