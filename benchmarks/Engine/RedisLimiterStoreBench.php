<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Benchmarks\Engine;

use Afzal\DynamicThrottle\Benchmarks\Services;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use PhpBench\Attributes as Bench;

/**
 * SPEC-003: one attempt with the Redis driver, a single EVALSHA of the Lua
 * script, per client and server. Time comes from Redis TIME, as in production.
 */
#[Bench\Revs(500)]
#[Bench\Iterations(10)]
#[Bench\Warmup(1)]
#[Bench\BeforeMethods('setUp')]
#[Bench\ParamProviders('connections')]
final class RedisLimiterStoreBench
{
    /** A limit no benchmark reaches, so every attempt is allowed. */
    private const int UNREACHABLE = 1_000_000_000;

    /** One hour, so a run rarely crosses into the next window. */
    private const int WINDOW = 3_600;

    private RedisLimiterStore $store;

    private CounterKey $allowed;

    private CounterKey $rejected;

    /**
     * @return iterable<string, array{client: string, server: 'redis'|'valkey'}>
     */
    public function connections(): iterable
    {
        foreach (['phpredis', 'predis'] as $client) {
            foreach (['redis', 'valkey'] as $server) {
                yield "{$server} ({$client})" => ['client' => $client, 'server' => $server];
            }
        }
    }

    /**
     * @param  array{client: string, server: 'redis'|'valkey'}  $params
     */
    public function setUp(array $params): void
    {
        $this->store = new RedisLimiterStore(Services::redis($params['client'], $params['server']), 'default', new KeyBuilder(Services::prefix()));
        $this->allowed = new CounterKey('1', hash('sha256', 'allowed'));
        $this->rejected = new CounterKey('1', hash('sha256', 'rejected'));

        // Max 1 is used up, so every benchmarked attempt on this counter is rejected.
        $this->store->attempt($this->rejected, 1, self::WINDOW);
    }

    public function benchAllowed(): void
    {
        $this->store->attempt($this->allowed, self::UNREACHABLE, self::WINDOW);
    }

    public function benchRejected(): void
    {
        $this->store->attempt($this->rejected, 1, self::WINDOW);
    }
}
