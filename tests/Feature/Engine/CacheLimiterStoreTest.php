<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

covers(CacheLimiterStore::class);

/*
| SPEC-002: behavior specific to the cache driver. The shared behavior is in
| tests/Contract/LimiterStoreContractTest.php.
*/

it('AC-9: keeps only allowed attempts in the current window key', function (): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore(Cache::store('array'), new KeyBuilder('dt:'), $clock);
    $hash = hash('sha256', 'A');

    foreach (range(1, 6) as $ignored) {
        $store->attempt(new CounterKey('1', $hash), 5, 60);
    }

    expect(Cache::store('array')->get("dt:c:1:60:{$hash}:30000000"))->toEqual(5);
});

it('AC-17: reads a negative previous-window count as 0', function (): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 70);
    $store = new CacheLimiterStore(Cache::store('array'), new KeyBuilder('dt:'), $clock);
    $hash = hash('sha256', 'A');
    Cache::store('array')->put("dt:c:1:60:{$hash}:30000000", -3, 125);

    $attempt = $store->attempt(new CounterKey('1', $hash), 5, 60);

    expect([$attempt->allowed, $attempt->remaining])->toBe([true, 4]);
});

it('AC-10: lets window keys expire 2 × window + 5 seconds after creation', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
    $store = new CacheLimiterStore(Cache::store('array'), new KeyBuilder('dt:'), new LaravelClock);
    $hash = hash('sha256', 'A');
    $windowKey = "dt:c:1:60:{$hash}:30000000";

    $store->attempt(new CounterKey('1', $hash), 5, 60);

    $this->travel(124)->seconds();
    $stillThere = Cache::store('array')->has($windowKey);

    $this->travel(2)->seconds();

    expect($stillThere)->toBeTrue()
        ->and(Cache::store('array')->has($windowKey))->toBeFalse();
});

it('AC-11: reports a store that throws as unavailable', function (): void {
    $failure = new RuntimeException('Connection refused');
    $cache = new Repository(new class($failure) extends ArrayStore
    {
        public function __construct(private readonly RuntimeException $failure)
        {
            parent::__construct();
        }

        public function increment($key, $value = 1)
        {
            throw $this->failure;
        }
    });
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt:'), new FakeClock);

    expect(fn () => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(function (StorageUnavailableException $exception) use ($failure): void {
            expect($exception->getPrevious())->toBe($failure);
        });
});

it('AC-11: reports a store whose increment returns false as unavailable', function (): void {
    // This is how Memcached reports that it is down.
    $cache = new Repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1)
        {
            return false;
        }
    });
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt:'), new FakeClock);

    expect(fn () => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
});
