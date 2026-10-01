<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestState;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-006 AC-12 to AC-18 and AC-20: reading the manifest on the request
| path. The repository is built by hand here, with a fake clock and a fake
| rebuilder. AC-18 uses the container's, with the real compiler.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Counts the store's reads, and throws on them once $failing is set.
 *
 * @return ArrayStore&object{reads: int, failing: bool}
 */
function manifestStore(): ArrayStore
{
    return new class extends ArrayStore
    {
        public int $reads = 0;

        public bool $failing = false;

        public function get($key): mixed
        {
            $this->reads++;

            return $this->failing ? throw new RuntimeException('The store is down.') : parent::get($key);
        }
    };
}

/**
 * Counts rebuilds, and runs $then after each one, such as writing a new manifest.
 *
 * @return ManifestRebuilder&object{calls: int, then: ?Closure}
 */
function fakeRebuilder(?Closure $then = null): ManifestRebuilder
{
    return new class($then) implements ManifestRebuilder
    {
        public int $calls = 0;

        public function __construct(public ?Closure $then) {}

        public function rebuild(): void
        {
            $this->calls++;
            ($this->then)?->__invoke();
        }
    };
}

function manifestRepository(Repository $cache, FakeClock $clock, ManifestRebuilder $rebuilder, int $refreshSeconds = 2): ManifestRepository
{
    return new ManifestRepository($cache, new KeyBuilder('dt:'), $clock, $rebuilder, app(), $refreshSeconds);
}

it('AC-12: reads the stored manifest when memory is empty', function (): void {
    $cache = new Repository(manifestStore());
    $cache->forever('dt:m', ManifestFixtures::json(7));

    $manifest = manifestRepository($cache, new FakeClock, fakeRebuilder())->current();

    expect([$manifest->version, $manifest->state, count($manifest->rules())])->toBe([7, ManifestState::Fresh, 1]);
});

it('AC-13: answers from memory within refresh_seconds, then reads the store again', function (): void {
    $store = manifestStore();
    $cache = new Repository($store);
    $cache->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    $repository = manifestRepository($cache, $clock, fakeRebuilder());

    $first = $repository->current();
    $cache->forever('dt:m', ManifestFixtures::json(8));
    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 + 1_999_999);
    $withinRefresh = $repository->current();
    $readsWithinRefresh = $store->reads;
    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 + 2_000_000);
    $afterRefresh = $repository->current();

    expect([$first->version, $withinRefresh->version, $afterRefresh->version])->toBe([7, 7, 8])
        ->and([$readsWithinRefresh, $store->reads])->toBe([1, 2]);
});

it('SPEC-028 AC-3: reads the store again when the clock goes back past the read time', function (): void {
    $store = manifestStore();
    $cache = new Repository($store);
    $cache->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    $repository = manifestRepository($cache, $clock, fakeRebuilder());
    $repository->current();
    $cache->forever('dt:m', ManifestFixtures::json(8));

    // An NTP step back, by a microsecond.
    $clock->setMicroseconds(FakeClock::T0 * 1_000_000 - 1);
    $manifest = $repository->current();

    expect([$manifest->version, $store->reads])->toBe([8, 2]);
});

it('AC-13: reads the store on every call when refresh_seconds is 0', function (): void {
    $store = manifestStore();
    $cache = new Repository($store);
    $cache->forever('dt:m', ManifestFixtures::json(7));
    $repository = manifestRepository($cache, new FakeClock, fakeRebuilder(), refreshSeconds: 0);

    $repository->current();
    $repository->current();
    $repository->current();

    expect($store->reads)->toBe(3);
});

it('AC-14: keeps serving the last known manifest, marked stale, and schedules a rebuild', function (): void {
    $cache = new Repository(manifestStore());
    $cache->forever('dt:m', ManifestFixtures::json(7));
    $clock = new FakeClock;
    $rebuilder = fakeRebuilder();
    $repository = manifestRepository($cache, $clock, $rebuilder);
    $repository->current();

    $cache->forget('dt:m');
    $clock->setSeconds(FakeClock::T0 + 3);
    $manifest = $repository->current();
    $beforeTerminate = $rebuilder->calls;
    app()->terminate();

    expect([$manifest->version, $manifest->state])->toBe([7, ManifestState::Stale])
        ->and([$beforeTerminate, $rebuilder->calls])->toBe([0, 1]);
});

it('AC-15: serves no rules, and rebuilds once, after the response', function (): void {
    $cache = new Repository(manifestStore());
    $rebuilder = fakeRebuilder(fn (): bool => $cache->forever('dt:m', ManifestFixtures::json(9)));
    $repository = manifestRepository($cache, new FakeClock, $rebuilder);

    $manifests = [$repository->current(), $repository->current(), $repository->current()];
    $beforeTerminate = $rebuilder->calls;
    app()->terminate();
    $rebuilt = $repository->current();

    expect(array_map(fn (Manifest $manifest): array => [$manifest->state, $manifest->rules()], $manifests))
        ->toBe(array_fill(0, 3, [ManifestState::Missing, []]))
        ->and([$beforeTerminate, $rebuilder->calls])->toBe([0, 1])
        ->and([$rebuilt->version, $rebuilt->state])->toBe([9, ManifestState::Fresh]);
});

it('AC-16: treats a corrupt manifest as missing, and reports it', function (string $corrupt): void {
    Exceptions::fake();
    $cache = new Repository(manifestStore());
    $cache->forever('dt:m', $corrupt);
    $rebuilder = fakeRebuilder();

    $manifest = manifestRepository($cache, new FakeClock, $rebuilder)->current();
    app()->terminate();

    expect($manifest->state)->toBe(ManifestState::Missing)
        ->and($rebuilder->calls)->toBe(1);
    Exceptions::assertReportedCount(1);
    Exceptions::assertReported(InvalidManifest::class);
})->with([
    'not JSON' => ['{'],
    'format 1, from before exemptions (SPEC-009 AC-12)' => ['{"format":1,"version":1,"rules":[]}'],
]);

it('AC-17: treats a store that throws as missing, never throws, and reports once per 30 seconds', function (): void {
    Exceptions::fake();
    $store = manifestStore();
    $store->failing = true;
    $clock = new FakeClock;
    $repository = manifestRepository(new Repository($store), $clock, fakeRebuilder());

    $first = $repository->current();
    $clock->setSeconds(FakeClock::T0 + 29);
    $repository->current();
    $reportedWithin30Seconds = count(Exceptions::reported());
    $clock->setSeconds(FakeClock::T0 + 30);
    $repository->current();

    expect($first->state)->toBe(ManifestState::Missing)
        ->and($reportedWithin30Seconds)->toBe(1);
    Exceptions::assertReportedCount(2);
});

it('AC-18: runs no database query before the response', function (): void {
    RuleSchema::migrate();
    Rule::create(RuleSchema::attributes());
    $queries = 0;
    Event::listen(QueryExecuted::class, function () use (&$queries): void {
        $queries++;
    });

    $warm = app(ManifestRepository::class)->current();
    $queriesWarm = $queries;
    ManifestRepository::flushState();
    Cache::forget('dt:m');
    $missing = app(ManifestRepository::class)->current();
    $queriesBeforeResponse = $queries;
    app()->terminate();

    expect([$warm->state, $queriesWarm])->toBe([ManifestState::Fresh, 0])
        ->and([$missing->state, $queriesBeforeResponse])->toBe([ManifestState::Missing, 0])
        ->and($queries)->toBeGreaterThan(0)
        ->and(Cache::get('dt:m'))->toBeString();
});

it('AC-20: keeps the manifest in memory for the whole process, until flushState()', function (): void {
    $cache = new Repository(manifestStore());
    $cache->forever('dt:m', ManifestFixtures::json(7));
    $first = manifestRepository($cache, new FakeClock, fakeRebuilder());
    $first->current();
    $cache->forget('dt:m');

    $fromAnotherInstance = manifestRepository($cache, new FakeClock, fakeRebuilder())->current();
    ManifestRepository::flushState();
    $afterFlush = manifestRepository($cache, new FakeClock, fakeRebuilder())->current();

    expect([$fromAnotherInstance->version, $fromAnotherInstance->state])->toBe([7, ManifestState::Fresh])
        ->and($afterFlush->state)->toBe(ManifestState::Missing);
});
