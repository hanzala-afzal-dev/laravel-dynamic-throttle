<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Manifest\ManifestUnavailable;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Tests\Support\CountingCacheStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\LocalManifests;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Afzal\DynamicThrottle\Tests\Support\RecordingRebuilder;
use Afzal\DynamicThrottle\Tests\Support\ThrowsWhenReleased;
use Afzal\DynamicThrottle\Tests\Support\UnreadableEntry;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Exceptions;

pest()->extend(TestCase::class);

/*
| SPEC-028 AC-1 to AC-4 and AC-9 to AC-11: the read path with L1 in APCu,
| between L0, the process's memory, and L2, the cache store. Each PHP-FPM
| request starts with L0 empty, which ManifestRepository::flushState() does.
| The repository is built by hand, with a fake clock and refresh_seconds 2.
*/

beforeEach(function (): void {
    if (! ApcuManifestCache::available()) {
        $this->markTestSkipped('APCu is off. Run composer test:apcu.');
    }

    apcu_clear_cache();
    ManifestRepository::flushState();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

function repositoryWithL1(CountingCacheStore $store, FakeClock $clock, ?RecordingRebuilder $rebuilder = null): ManifestRepository
{
    return new ManifestRepository(new Repository($store), new KeyBuilder('dt:'), $clock, $rebuilder ?? new RecordingRebuilder, app(), 2, LocalManifests::cache());
}

/**
 * As a new PHP-FPM request starts: nothing in the process's memory.
 */
function emptyL0(): void
{
    ManifestRepository::flushState();
}

it('AC-1: builds the manifest from a fresh L1 entry, and reads nothing from the store', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 1) * 1_000_000));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, $store->reads('dt:m')])->toBe([7, ManifestState::Fresh, 0]);
});

it('AC-1: keeps the manifest in L0 only for what is left of the entry\'s freshness', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 1) * 1_000_000));
    $clock = new FakeClock;
    $repository = repositoryWithL1($store, $clock);

    $repository->current();
    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 + 999_999);
    $fromL0 = $repository->current();
    $clock->setSeconds(FakeClock::T0 + 1);
    $refreshed = $repository->current();

    expect([$fromL0->version, $refreshed->version, $store->reads('dt:m')])->toBe([7, 8, 1]);
});

it('AC-2: fills L1 from the store, so the next PHP-FPM request reads nothing from it', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;

    $first = repositoryWithL1($store, $clock)->current();
    emptyL0();
    $second = repositoryWithL1($store, $clock)->current();
    $entry = LocalManifests::cache()->fetch();

    expect([$first->version, $second->version, $second->state, $store->reads('dt:m')])->toBe([7, 7, ManifestState::Fresh, 1])
        ->and([$entry?->version, $entry?->state, $entry?->readAt])->toBe([7, ManifestState::Fresh, FakeClock::T0 * 1_000_000]);
});

it('AC-3: uses the entry until refresh_seconds after its read, then reads the store, and a rule saved since applies', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    repositoryWithL1($store, $clock)->current();
    // A rule saved at T0 + 1.
    $store->forever('dt:m', ManifestFixtures::json(8, [['max_attempts' => 5]]));

    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 + 1_999_999);
    emptyL0();
    $withinRefresh = repositoryWithL1($store, $clock)->current();
    $clock->setSeconds(FakeClock::T0 + 2);
    emptyL0();
    $afterRefresh = repositoryWithL1($store, $clock)->current();

    expect([$withinRefresh->version, $afterRefresh->version, $afterRefresh->rule(0)->maxAttempts, $store->reads('dt:m')])
        ->toBe([7, 8, 5, 2]);
});

it('AC-3: reads the store over an entry read in the future, after the clock went back', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: FakeClock::T0 * 1_000_000 + 1));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $store->reads('dt:m')])->toBe([8, 1]);
});

it('AC-4: serves L1\'s manifest, marked stale, when the store has lost it, and the server reads nothing more for refresh_seconds', function (): void {
    Exceptions::fake();
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    $rebuilder = new RecordingRebuilder;
    repositoryWithL1($store, $clock, $rebuilder)->current();
    $store->forget('dt:m');

    $clock->setSeconds(FakeClock::T0 + 3);
    emptyL0();
    $manifest = repositoryWithL1($store, $clock, $rebuilder)->current();
    $clock->setMicroseconds((FakeClock::T0 + 5) * 1_000_000 - 1);
    emptyL0();
    $next = repositoryWithL1($store, $clock, $rebuilder)->current();
    $rebuildsBeforeTerminate = $rebuilder->calls;
    app()->terminate();

    expect([$manifest->version, $manifest->state, $next->version, $next->state])->toBe([7, ManifestState::Stale, 7, ManifestState::Stale])
        ->and($store->reads('dt:m'))->toBe(2)
        ->and([$rebuildsBeforeTerminate, $rebuilder->calls])->toBe([0, 1]);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(ManifestUnavailable::class);
});

it('AC-4: serves L1\'s manifest, marked stale, while the store fails, and the server reads nothing more for refresh_seconds', function (): void {
    Exceptions::fake();
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    repositoryWithL1($store, $clock)->current();
    $store->failing = true;

    $clock->setSeconds(FakeClock::T0 + 3);
    emptyL0();
    $manifest = repositoryWithL1($store, $clock)->current();
    $clock->setSeconds(FakeClock::T0 + 4);
    emptyL0();
    $next = repositoryWithL1($store, $clock)->current();

    expect([$manifest->version, $manifest->state, $next->version, $next->state])->toBe([7, ManifestState::Stale, 7, ManifestState::Stale])
        ->and($store->reads('dt:m'))->toBe(2);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(fn (ManifestUnavailable $exception): bool => $exception->getMessage() === 'The manifest could not be read: '.CountingCacheStore::DOWN);
});

it('AC-4: serves the newer of L0\'s and L1\'s manifests when the store has lost it, and writes it back to L1', function (int $inMemory, int $inApcu): void {
    Exceptions::fake();
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json($inMemory));
    $clock = new FakeClock((FakeClock::T0 - 3) * 1_000_000);
    // A long-running worker read its manifest 3 seconds ago, into L0 only.
    (new ManifestRepository(new Repository($store), new KeyBuilder('dt:'), $clock, new RecordingRebuilder, app(), 2))->current();
    // Another worker of the server read another one at the same time, into L1.
    LocalManifests::cache()->store(LocalManifests::snapshot($inApcu, readAt: (FakeClock::T0 - 3) * 1_000_000));
    $store->forget('dt:m');

    $clock->setSeconds(FakeClock::T0);
    $manifest = repositoryWithL1($store, $clock)->current();
    $entry = LocalManifests::cache()->fetch();

    expect([$manifest->version, $manifest->state])->toBe([8, ManifestState::Stale])
        ->and([$entry?->version, $entry?->state, $entry?->readAt])->toBe([8, ManifestState::Stale, FakeClock::T0 * 1_000_000]);
})->with([
    'L1\'s is newer' => [7, 8],
    'L0\'s is newer' => [8, 7],
]);

it('AC-4: serves no rules when neither L0 nor L1 holds a manifest, and keeps nothing in L1', function (): void {
    Exceptions::fake();
    $store = new CountingCacheStore;

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->state, $manifest->rules(), LocalManifests::cache()->fetch()])->toBe([ManifestState::Missing, [], null]);
});

it('AC-4: writes the stale manifest back with the time after a store that takes time to fail, so the server waits on it once', function (): void {
    Exceptions::fake();
    $clock = new FakeClock;
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000));
    $store = new CountingCacheStore;
    $store->failing = true;
    // Each read waits 1.5 seconds before it fails, as a store that times out does.
    $store->beforeRead = fn () => $clock->setMicroseconds($clock->microseconds() + 1_500_000);

    $manifest = repositoryWithL1($store, $clock)->current();
    $entry = LocalManifests::cache()->fetch();
    $readsBefore = $store->reads('dt:m');
    emptyL0();
    $next = repositoryWithL1($store, $clock)->current();

    expect([$manifest->version, $manifest->state, $next->version])->toBe([7, ManifestState::Stale, 7])
        ->and($entry?->readAt)->toBe(FakeClock::T0 * 1_000_000 + 1_500_000)
        ->and($store->reads('dt:m'))->toBe($readsBefore);
});

it('AC-4: still writes the stale manifest back, and throws nothing, when reporting the store\'s failure fails', function (): void {
    app()->instance(ExceptionHandler::class, new class implements ExceptionHandler
    {
        public function report(Throwable $e): void
        {
            throw new RuntimeException('The log is down.');
        }

        public function shouldReport(Throwable $e): bool
        {
            return true;
        }

        public function render($request, Throwable $e): never
        {
            throw $e;
        }

        public function renderForConsole($output, Throwable $e): void {}
    });
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000));
    $store = new CountingCacheStore;
    $store->failing = true;

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, LocalManifests::cache()->fetch()?->readAt])->toBe([7, ManifestState::Stale, FakeClock::T0 * 1_000_000]);
});

it('AC-15: serves the expired entry while another process of the server reads the store, and reads nothing itself', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000));
    LocalManifests::cache()->claim(2);

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $store->reads('dt:m')])->toBe([7, 0]);
});

it('AC-15: claims the refresh of an expired entry, reads the store, and ends the claim when it stores the entry', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->store(LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $store->reads('dt:m'), LocalManifests::cache()->fetch()?->version])->toBe([8, 1, 8])
        ->and(LocalManifests::cache()->claim(2))->toBeTrue();
});

it('AC-15: reads the store when L1 holds no entry, without a claim to wait for', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));
    LocalManifests::cache()->claim(2);

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $store->reads('dt:m')])->toBe([8, 1]);
});

it('AC-9: throws nothing for an object deep in an entry, even with the store down', function (): void {
    Exceptions::fake();
    apcu_store(LocalManifests::key(), [
        ...LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000)->toArray(),
        'exemptions' => [[...ManifestFixtures::exemption(), 'id' => new ThrowsWhenReleased]],
    ]);
    $store = new CountingCacheStore;
    $store->failing = true;

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->state, apcu_exists(LocalManifests::key())])->toBe([ManifestState::Missing, false]);
});

it('AC-10: serves a manifest that cannot be written to L1, and throws nothing', function (): void {
    // json_decode() reads 1e400 as INF, which json_encode() cannot write.
    $json = str_replace('"response_override":null', '"response_override":{"retry":1e400}', ManifestFixtures::json(7));
    $store = new CountingCacheStore;
    $store->forever('dt:m', $json);

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, count($manifest->rules()), apcu_exists(LocalManifests::key())])->toBe([7, ManifestState::Fresh, 1, false]);
});

it('AC-9: reads the store over an entry that is not a snapshot, replaces the entry, and throws nothing', function (): void {
    apcu_store(LocalManifests::key(), new UnreadableEntry);
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, LocalManifests::cache()->fetch()?->version])->toBe([7, ManifestState::Fresh, 7]);
});

it('AC-9: reads the store over an entry that cannot build a manifest, replaces the entry, and throws nothing', function (array $replace): void {
    apcu_store(LocalManifests::key(), [...LocalManifests::snapshot(7)->toArray(), ...$replace]);
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(8));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, LocalManifests::cache()->fetch()?->version])->toBe([8, ManifestState::Fresh, 8]);
})->with([
    'an index that points past its rules' => [['index' => ['literal' => [], 'wildcard' => ['*' => [0, 1]], 'patterns' => ['api/*']]]],
    'an exemption that is not an exemption' => [['exemptions' => [ManifestFixtures::exemption(['match' => 'not a range'])]]],
]);

it('AC-9: serves no rules, and throws nothing, when the store fails and L1\'s entry cannot build a manifest', function (): void {
    Exceptions::fake();
    $entry = LocalManifests::snapshot(7, readAt: (FakeClock::T0 - 3) * 1_000_000)->toArray();
    apcu_store(LocalManifests::key(), [...$entry, 'index' => [...$entry['index'], 'patterns' => []]]);
    $store = new CountingCacheStore;
    $store->failing = true;

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->state, $manifest->rules()])->toBe([ManifestState::Missing, []]);
});

it('AC-10: serves the store\'s rules when APCu cannot hold them, and throws nothing', function (): void {
    $store = new CountingCacheStore;
    $store->forever('dt:m', ManifestFixtures::json(7, [['response_override' => ['message' => str_repeat('x', (int) apcu_sma_info(true)['seg_size'])]]]));

    $manifest = repositoryWithL1($store, new FakeClock)->current();

    expect([$manifest->version, $manifest->state, apcu_exists(LocalManifests::key())])->toBe([7, ManifestState::Fresh, false]);
});

it('AC-11: keeps in L1 the index built from the rules, and builds from it the manifest the store holds', function (): void {
    $json = ManifestFixtures::json(7, [
        [],
        ['id' => '2', 'method' => 'POST', 'pattern' => 'api/orders', 'scope' => 'user', 'on_missing_identity' => 'skip', 'priority' => 10],
        ['id' => '3', 'pattern' => 'api/users/*/posts', 'scope' => 'custom', 'scope_parameter' => 'tenant', 'mode' => 'dry_run', 'on_failure' => 'closed', 'response_override' => ['message' => 'Slow down']],
    ], [
        [],
        ['id' => '2', 'rule' => '2', 'subject' => 'user', 'match' => str_repeat('a', 64), 'expires_at' => FakeClock::T0 + 60],
        ['id' => '3', 'subject' => 'custom', 'parameter' => 'tenant', 'match' => str_repeat('b', 64)],
    ]);
    $store = new CountingCacheStore;
    $store->forever('dt:m', $json);
    $clock = new FakeClock;
    repositoryWithL1($store, $clock)->current();

    emptyL0();
    $fromL1 = repositoryWithL1($store, $clock)->current();
    $decoded = ManifestCodec::decode($json);

    expect($store->reads('dt:m'))->toBe(1)
        ->and(apcu_fetch(LocalManifests::key())['index'] ?? null)->toBe(RuleIndex::of($decoded->rules())->toArray())
        ->and([$fromL1->version, $fromL1->state, $fromL1->rules(), $fromL1->index, $fromL1->exemptions])
        ->toEqual([$decoded->version, $decoded->state, $decoded->rules(), $decoded->index, $decoded->exemptions]);
});
