<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use LogicException;

/**
 * Picks and builds the counter driver from config, without network calls (ADR-0005, SPEC-003).
 */
final readonly class LimiterStoreFactory
{
    public function __construct(
        private Config $config,
        private CacheFactory $cache,
        private RedisFactory $redis,
        private KeyBuilder $keys,
        private Clock $clock,
    ) {}

    public function make(): LimiterStore
    {
        throw new LogicException('Not implemented yet (SPEC-003).');
    }
}
