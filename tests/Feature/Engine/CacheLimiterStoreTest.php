<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Attempt;
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

it('AC-17: reads counts stored as numeric strings, as Redis and Memcached return them', function (): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 60);
    $store = new CacheLimiterStore(Cache::store('array'), new KeyBuilder('dt:'), $clock);
    $hash = hash('sha256', 'A');
    Cache::store('array')->put("dt:c:1:60:{$hash}:30000000", '5', 125);

    // The previous window still weighs 5 × 60 / 60 = 5 at the start of this one.
    expect($store->attempt(new CounterKey('1', $hash), 5, 60)->allowed)->toBeFalse();
});

it('AC-17: reads a negative current-window count as 0', function (): void {
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
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

    // Half a second on either side of 125 s, so a TTL of 124 or 126 would fail.
    $this->travel(124_500)->milliseconds();
    $stillThere = Cache::store('array')->has($windowKey);

    $this->travel(1_000)->milliseconds();

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

        public function increment($key, $value = 1): void
        {
            throw $this->failure;
        }
    });
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt:'), new FakeClock);

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(function (StorageUnavailableException $exception) use ($failure): void {
            expect($exception->getPrevious())->toBe($failure);
        });
});

it('AC-18: keeps a rejection when undoing its increment fails', function (): void {
    $cache = new Repository(new class extends ArrayStore
    {
        public function decrement($key, $value = 1): void
        {
            throw new RuntimeException('Connection lost');
        }
    });
    $clock = new FakeClock;
    $clock->setSeconds(FakeClock::T0 + 10);
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt:'), $clock);
    $key = new CounterKey('1', hash('sha256', 'A'));

    foreach (range(1, 5) as $ignored) {
        $store->attempt($key, 5, 60);
    }
    $rejected = $store->attempt($key, 5, 60);

    expect($rejected->allowed)->toBeFalse()
        ->and($rejected->retryAfter)->toBe(62)
        ->and($cache->get("dt:c:1:60:{$key->identityHash}:30000000"))->toEqual(6);
});

it('AC-11: reports a failing database query as unavailable, whatever its SQLSTATE code', function (): void {
    // "no such table" carries the string SQLSTATE code HY000, not an integer.
    $cache = Cache::build(['driver' => 'database', 'connection' => 'testing', 'table' => 'missing_cache_table']);
    $store = new CacheLimiterStore($cache, new KeyBuilder('dt:'), new FakeClock);

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
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

    expect(fn (): Attempt => $store->attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60))
        ->toThrow(StorageUnavailableException::class);
});

/*
| SPEC-004: release() with the cache driver.
*/

it('SPEC-004 AC-7: releasing a rejected attempt makes no call to the store', function (): void {
    $arrayStore = new class extends ArrayStore
    {
        /** @var list<string> */
        public array $calls = [];

        public function get($key): mixed
        {
            $this->calls[] = 'get';

            return parent::get($key);
        }

        public function many(array $keys): mixed
        {
            $this->calls[] = 'many';

            return parent::many($keys);
        }

        public function put($key, $value, $seconds): mixed
        {
            $this->calls[] = 'put';

            return parent::put($key, $value, $seconds);
        }

        public function increment($key, $value = 1): mixed
        {
            $this->calls[] = 'increment';

            return parent::increment($key, $value);
        }

        public function decrement($key, $value = 1): mixed
        {
            $this->calls[] = 'decrement';

            return parent::decrement($key, $value);
        }

        public function forget($key): mixed
        {
            $this->calls[] = 'forget';

            return parent::forget($key);
        }
    };
    $store = new CacheLimiterStore(new Repository($arrayStore), new KeyBuilder('dt:'), new FakeClock);

    $store->release(new Attempt(new CounterKey('1', hash('sha256', 'A')), 5, 60, 30_000_000, false, 0, 62));

    expect($arrayStore->calls)->toBe([]);
});

it('SPEC-004 AC-10: never creates a window key', function (): void {
    $store = new CacheLimiterStore(Cache::store('array'), new KeyBuilder('dt:'), new FakeClock);
    $hash = hash('sha256', 'A');

    // Allowed, but its window key is gone: expired or evicted.
    $store->release(new Attempt(new CounterKey('1', $hash), 5, 60, 30_000_000, true, 4, 0));

    expect(Cache::store('array')->has("dt:c:1:60:{$hash}:30000000"))->toBeFalse();
});

it('SPEC-004 AC-12: reports a store that throws while releasing as unavailable', function (string $failing): void {
    $failure = new RuntimeException('Connection refused');
    $arrayStore = new class($failure, $failing) extends ArrayStore
    {
        public function __construct(private readonly RuntimeException $failure, private readonly string $failing)
        {
            parent::__construct();
        }

        public function get($key): mixed
        {
            return $this->failing === 'get' ? throw $this->failure : parent::get($key);
        }

        public function decrement($key, $value = 1): int|bool
        {
            return $this->failing === 'decrement' ? throw $this->failure : parent::decrement($key, $value);
        }
    };
    $hash = hash('sha256', 'A');
    $arrayStore->put("dt:c:1:60:{$hash}:30000000", 3, 125);
    $store = new CacheLimiterStore(new Repository($arrayStore), new KeyBuilder('dt:'), new FakeClock);

    expect(fn () => $store->release(new Attempt(new CounterKey('1', $hash), 5, 60, 30_000_000, true, 1, 0)))
        ->toThrow(function (StorageUnavailableException $exception) use ($failure): void {
            expect($exception->getPrevious())->toBe($failure)
                ->and($exception->getMessage())->toBe('The limiter store could not release the request.');
        });
})->with(['get', 'decrement']);
