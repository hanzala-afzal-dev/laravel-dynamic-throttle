<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use LogicException;

/**
 * The Redis and Valkey driver: one Lua script per attempt (ADR-0005, SPEC-003).
 */
final readonly class RedisLimiterStore implements LimiterStore
{
    public function __construct(
        private RedisFactory $redis,
        /** The Redis connection name; public so driver selection can be checked. */
        public string $connection,
        private KeyBuilder $keys,
        /** Replaces Redis TIME. Only for tests (ADR-0006). */
        private ?Clock $testClock = null,
    ) {}

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
    {
        throw new LogicException('Not implemented yet (SPEC-003).');
    }

    /**
     * The SHA-1 that EVALSHA uses for the attempt script.
     *
     * @internal
     */
    public static function scriptSha(): string
    {
        throw new LogicException('Not implemented yet (SPEC-003).');
    }
}
