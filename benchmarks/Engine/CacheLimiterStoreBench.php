<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Engine;

use Afzal\DynamicThrottle\Benchmarks\Services;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use PhpBench\Attributes as Bench;

/**
 * SPEC-002: one attempt with the cache driver, per store. Allowed attempts take
 * add, increment and get; rejected ones also undo their increment.
 */
#[Bench\Revs(500)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('stores')]
final class CacheLimiterStoreBench
{
    /** A limit no benchmark reaches, so every attempt is allowed. */
    private const int UNREACHABLE = 1_000_000_000;

    private CacheLimiterStore $store;

    private CounterKey $allowed;

    private CounterKey $rejected;

    /**
     * @return iterable<string, array{store: string}>
     */
    public function stores(): iterable
    {
        yield 'array' => ['store' => 'array'];
        yield 'redis (phpredis)' => ['store' => 'redis'];
        yield 'memcached' => ['store' => 'memcached'];
    }

    /**
     * @param  array{store: string}  $params
     */
    public function setUp(array $params): void
    {
        $clock = new FakeClock;
        $clock->setSeconds(FakeClock::T0 + 10);

        $this->store = new CacheLimiterStore(Services::cache($params['store']), new KeyBuilder(Services::prefix()), $clock);
        $this->allowed = new CounterKey('1', hash('sha256', 'allowed'));
        $this->rejected = new CounterKey('1', hash('sha256', 'rejected'));

        // Max 1 is used up, so every benchmarked attempt on this counter is rejected.
        $this->store->attempt($this->rejected, 1, 60);
    }

    public function benchAllowed(): void
    {
        $this->store->attempt($this->allowed, self::UNREACHABLE, 60);
    }

    public function benchRejected(): void
    {
        $this->store->attempt($this->rejected, 1, 60);
    }
}
