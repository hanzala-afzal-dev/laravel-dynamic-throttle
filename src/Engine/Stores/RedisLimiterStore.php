<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use InvalidArgumentException;
use Predis\ClientInterface as PredisClient;
use Predis\Response\ErrorInterface as PredisError;
use Predis\Response\ServerException as PredisServerException;
use Redis;
use RedisCluster;
use Throwable;

/**
 * The Redis and Valkey driver: one Lua script per attempt (ADR-0005, SPEC-003).
 *
 * One hash per counter, one field per window index, so every script touches a
 * single key and is safe on Redis Cluster. The script is a port of
 * SlidingWindowCounter that does the same floating-point operations in the same
 * order, so both make identical decisions (checked by a model-based test).
 */
final readonly class RedisLimiterStore implements LimiterStore
{
    /**
     * KEYS[1]: the counter's hash. ARGV[1]: max attempts. ARGV[2]: window in seconds.
     * ARGV[3]: the time in microseconds, or '' to read Redis TIME (the argument is for tests).
     * Returns {allowed (1 or 0), remaining, retry after, window index}, integers only,
     * because Redis truncates Lua numbers in replies.
     */
    private const string SCRIPT = <<<'LUA'
        local max = tonumber(ARGV[1])
        local window = tonumber(ARGV[2])
        local now
        if ARGV[3] == '' then
            local time = redis.call('TIME')
            now = tonumber(time[1]) * 1000000 + tonumber(time[2])
        else
            now = tonumber(ARGV[3])
        end

        local window_us = window * 1000000
        local index = math.floor(now / window_us)
        if index * window_us > now then index = index - 1 end
        if (index + 1) * window_us <= now then index = index + 1 end
        local elapsed = (now - index * window_us) / 1000000

        local current = tonumber(redis.call('HGET', KEYS[1], string.format('%d', index))) or 0
        local previous = tonumber(redis.call('HGET', KEYS[1], string.format('%d', index - 1))) or 0
        if current < 0 then current = 0 end
        if previous < 0 then previous = 0 end

        local estimate = previous * (window - elapsed) / window + current

        if estimate + 1 <= max then
            redis.call('HINCRBY', KEYS[1], string.format('%d', index), 1)
            for _, field in ipairs(redis.call('HKEYS', KEYS[1])) do
                if tonumber(field) < index - 1 then
                    redis.call('HDEL', KEYS[1], field)
                end
            end
            redis.call('EXPIRE', KEYS[1], 2 * window + 5)
            return {1, max - math.ceil(estimate + 1), 0, index}
        end

        local until_next_window = window - elapsed
        local headroom = max - current - 1
        local wait
        if headroom > 0 then
            wait = until_next_window - headroom * window / previous
        elseif current == 0 then
            wait = until_next_window
        else
            wait = until_next_window + window - (max - 1) * window / current
        end

        local retry_after = math.ceil(wait)
        if retry_after < 1 then retry_after = 1 end

        return {0, 0, retry_after, index}
        LUA;

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
        // Same checks as the cache driver, before touching the store (SPEC-002 AC-16).
        AttemptGuard::check($maxAttempts, $windowSeconds);

        $reply = $this->run($this->keys->counterBase($key, $windowSeconds), [
            (string) $maxAttempts,
            (string) $windowSeconds,
            $this->testClock instanceof Clock ? (string) $this->testClock->microseconds() : '',
        ]);

        return new Attempt(
            key: $key,
            limit: $maxAttempts,
            windowSeconds: $windowSeconds,
            windowIndex: $reply[3],
            allowed: $reply[0] === 1,
            remaining: $reply[1],
            retryAfter: $reply[2],
        );
    }

    /**
     * The SHA-1 that EVALSHA uses for the attempt script. Redis requires SHA-1
     * here; it is a cache key, not a security measure.
     *
     * @internal
     */
    public static function scriptSha(): string
    {
        return hash('sha1', self::SCRIPT);
    }

    /**
     * Runs the script with EVALSHA and falls back to EVAL when Redis no longer
     * has it (NOSCRIPT, after a restart or failover). Laravel's phpredis evalsha()
     * sends SCRIPT LOAD on every call, so this talks to the clients directly.
     *
     * @param  list<string>  $arguments
     * @return array{int, int, int, int}
     */
    private function run(string $key, array $arguments): array
    {
        try {
            $connection = $this->redis->connection($this->connection);
            $client = $connection->client();

            $reply = match (true) {
                $client instanceof Redis, $client instanceof RedisCluster => $this->runWithPhpRedis($client, $key, $arguments),
                $client instanceof PredisClient => $this->runWithPredis($client, $key, $arguments),
                default => throw new InvalidArgumentException('Unsupported Redis client '.get_debug_type($client).'; use phpredis or predis.'),
            };
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The Redis limiter store could not count the request.', $exception);
        }

        if (! is_array($reply) || ! array_is_list($reply) || count($reply) !== 4 || count(array_filter($reply, is_int(...))) !== 4) {
            throw StorageUnavailableException::because('The Redis limiter store returned an unexpected reply.');
        }

        /** @var array{int, int, int, int} $reply */
        return $reply;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runWithPhpRedis(Redis|RedisCluster $client, string $key, array $arguments): mixed
    {
        $reply = $client->evalSha(self::scriptSha(), [$key, ...$arguments], 1);

        if ($reply === false && str_starts_with((string) $client->getLastError(), 'NOSCRIPT')) {
            $client->clearLastError();
            $reply = $client->eval(self::SCRIPT, [$key, ...$arguments], 1);
        }

        if ($reply === false) {
            throw StorageUnavailableException::because('Redis rejected the limiter script: '.$client->getLastError());
        }

        return $reply;
    }

    /**
     * @param  list<string>  $arguments
     */
    private function runWithPredis(PredisClient $client, string $key, array $arguments): mixed
    {
        try {
            $reply = $client->evalsha(self::scriptSha(), 1, $key, ...$arguments);
        } catch (PredisServerException $exception) {
            if ($exception->getErrorType() !== 'NOSCRIPT') {
                throw $exception;
            }

            $reply = null;
        }

        // Without the "exceptions" option, predis returns errors instead of throwing them.
        if ($reply === null || ($reply instanceof PredisError && $reply->getErrorType() === 'NOSCRIPT')) {
            $reply = $client->eval(self::SCRIPT, 1, $key, ...$arguments);
        }

        if ($reply instanceof PredisError) {
            throw StorageUnavailableException::because('Redis rejected the limiter script: '.$reply->getMessage());
        }

        return $reply;
    }
}
