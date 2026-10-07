<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\CacheTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\CountingCacheStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

covers(CacheTelemetryBuffer::class);

/*
| SPEC-015: what only the cache driver's buffer does. One key per minute, rule
| and metric, created with its time to live before it is incremented, as the
| counters are (decision 5). No offenders and no distinct clients (AC-4, AC-5).
*/

beforeEach(function (): void {
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

function cacheTelemetry(): CacheTelemetryBuffer
{
    return new CacheTelemetryBuffer(Cache::store('array'), new KeyBuilder('dt:'));
}

/**
 * When each of the array store's keys expires, in Unix seconds, by key.
 *
 * @return array<string, float>
 */
function cacheTelemetryExpiries(): array
{
    $store = Cache::store('array')->getStore();

    if (! $store instanceof ArrayStore) {
        throw new RuntimeException('The array cache store is not an ArrayStore.');
    }

    return array_map(fn (array $item): float => (float) $item['expiresAt'], $store->all());
}

it('AC-4 and AC-5: keeps no offenders and no distinct clients, and says so with null', function (): void {
    $buffer = cacheTelemetry();
    $buffer->record(Telemetry::minute(FakeClock::T0), 1, [Telemetry::blocked('1', 'A'), Telemetry::blocked('1', 'B')]);

    expect([$buffer->offenders('1', Telemetry::hour(FakeClock::T0)), $buffer->clients('1', Telemetry::hour(FakeClock::T0))])->toBe([null, null]);
});

it('AC-6: keeps one key per minute, rule and metric, which expires 7,200 seconds after it is first written', function (): void {
    $buffer = cacheTelemetry();
    $minute = Telemetry::minute(FakeClock::T0);

    $buffer->record($minute, 1, [Telemetry::blocked('1')]);
    // Later requests in the same minute never push the expiry back.
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 30));
    $buffer->record($minute, 1, [Telemetry::wouldBlock('2')]);

    expect(cacheTelemetryExpiries())->toBe([
        "dt:t:{$minute}:1:hits" => (float) FakeClock::T0 + 7_200,
        "dt:t:{$minute}:1:blocks" => (float) FakeClock::T0 + 7_200,
        "dt:t:{$minute}:2:hits" => (float) FakeClock::T0 + 30 + 7_200,
        "dt:t:{$minute}:2:would_blocks" => (float) FakeClock::T0 + 30 + 7_200,
    ]);
});

it('AC-15: reads only the rules it is asked for, since a cache store cannot list its keys', function (): void {
    $buffer = cacheTelemetry();
    $minute = Telemetry::minute(FakeClock::T0);
    $buffer->record($minute, 1, [Telemetry::counted('1'), Telemetry::counted('2')]);

    expect(Telemetry::counts($minute, ['2'], $buffer))->toBe(['2' => [1, 0, 0]]);
});

it('AC-8: reports a cache store that throws as unavailable, when recording and when reading', function (): void {
    $cache = new CountingCacheStore;
    $cache->failing = true;
    $buffer = new CacheTelemetryBuffer(new Repository($cache), new KeyBuilder('dt:'));
    $minute = Telemetry::minute(FakeClock::T0);

    expect(fn () => $buffer->record($minute, 1, [Telemetry::counted('1')]))->toThrow(StorageUnavailableException::class)
        ->and(fn (): array => $buffer->counts($minute, ['1']))->toThrow(StorageUnavailableException::class);
});

it('AC-8: reports an increment the store refuses without throwing as unavailable, naming the key', function (): void {
    // Memcached answers an outage with false.
    $buffer = new CacheTelemetryBuffer(new Repository(new class extends ArrayStore
    {
        public function increment($key, $value = 1): bool
        {
            return false;
        }
    }), new KeyBuilder('dt:'));
    $minute = Telemetry::minute(FakeClock::T0);

    expect(fn () => $buffer->record($minute, 1, [Telemetry::counted('1')]))
        ->toThrow(function (StorageUnavailableException $exception) use ($minute): void {
            expect([$exception->getMessage(), $exception->getPrevious()?->getMessage()])
                ->toBe(['The telemetry buffer could not record the request.', "The cache store did not increment dt:t:{$minute}:1:hits."]);
        });
});

it('AC-15: reads nothing from the store when it is asked for no rules', function (): void {
    $buffer = new CacheTelemetryBuffer(new Repository(new class extends ArrayStore
    {
        public function many(array $keys): array
        {
            throw new RuntimeException('The store was read.');
        }
    }), new KeyBuilder('dt:'));

    expect($buffer->counts(Telemetry::minute(FakeClock::T0), []))->toBe([]);
});

it('AC-1: reads a count that is not a whole number above 0 as 0', function (): void {
    $minute = Telemetry::minute(FakeClock::T0);
    $cache = Cache::store('array');
    $cache->put("dt:t:{$minute}:1:hits", 2, 60);
    $cache->put("dt:t:{$minute}:1:blocks", -3, 60);
    $cache->put("dt:t:{$minute}:1:would_blocks", 'many', 60);
    $cache->put("dt:t:{$minute}:2:hits", -1, 60);

    expect(Telemetry::counts($minute, ['1', '2'], cacheTelemetry()))->toBe(['1' => [2, 0, 0]]);
});
