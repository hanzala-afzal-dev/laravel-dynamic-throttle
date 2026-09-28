<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stores;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\BatchLimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Internal\AttemptGuard;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\OneCallPerAttempt;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Redis\Connections\PredisClusterConnection;
use Illuminate\Redis\Connections\PredisConnection;
use InvalidArgumentException;
use Predis\Response\ErrorInterface as PredisError;
use Predis\Response\ServerException as PredisServerException;
use Redis;
use RedisCluster;
use Throwable;

/**
 * The Redis and Valkey driver (ADR-0005, SPEC-003): one Lua script per request
 * (ADR-0026, SPEC-027), or per attempt through attempt().
 *
 * One hash per counter, one field per window index. The counting function is a
 * port of SlidingWindowCounter that does the same floating-point operations in
 * the same order, so both make identical decisions (checked by model-based
 * tests). On Redis Cluster a script may only touch keys in one hash slot, so
 * there a request makes one call per attempt, each touching a single key.
 */
final readonly class RedisLimiterStore implements BatchLimiterStore, LimiterStore
{
    /**
     * The time in microseconds, from the argument, or from Redis TIME when it is ''
     * (the argument is for tests). Then count(key, max, window, now), which counts
     * an attempt when it fits and returns allowed (1 or 0), the rounded-up estimate
     * with this request (0 when rejected), retry after, and the window index.
     * Integers only, because Redis truncates Lua numbers in replies. PHP computes
     * remaining from the estimate: a Lua number is a double, so max - estimate
     * would be inexact above 2^53 (AC-20).
     */
    private const string FUNCTIONS = <<<'LUA'
        local function time_from(argument)
            if argument == '' then
                local time = redis.call('TIME')
                return tonumber(time[1]) * 1000000 + tonumber(time[2])
            end
            return tonumber(argument)
        end

        local function count(key, max, window, now)
            local window_us = window * 1000000
            local index = math.floor(now / window_us)
            if index * window_us > now then index = index - 1 end
            if (index + 1) * window_us <= now then index = index + 1 end
            local elapsed = (now - index * window_us) / 1000000

            local current = tonumber(redis.call('HGET', key, string.format('%d', index))) or 0
            local previous = tonumber(redis.call('HGET', key, string.format('%d', index - 1))) or 0
            if current < 0 then current = 0 end
            if previous < 0 then previous = 0 end

            local estimate = previous * (window - elapsed) / window + current

            if estimate + 1 <= max then
                redis.call('HINCRBY', key, string.format('%d', index), 1)
                for _, field in ipairs(redis.call('HKEYS', key)) do
                    if tonumber(field) < index - 1 then
                        redis.call('HDEL', key, field)
                    end
                end
                redis.call('EXPIRE', key, 2 * window + 5)
                return 1, math.ceil(estimate + 1), 0, index
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

            return 0, 0, retry_after, index
        end

        LUA;

    /**
     * One attempt. KEYS[1]: the counter's hash. ARGV[1]: max attempts. ARGV[2]:
     * window in seconds. ARGV[3]: the time in microseconds, or ''. Returns count()'s
     * four integers.
     */
    private const string SCRIPT = self::FUNCTIONS.<<<'LUA'
        local allowed, estimate, retry_after, index = count(KEYS[1], tonumber(ARGV[1]), tonumber(ARGV[2]), time_from(ARGV[3]))
        return {allowed, estimate, retry_after, index}
        LUA;

    /**
     * A request's attempts, in order, at one time (ADR-0026). KEYS: the counters'
     * hashes. ARGV[1]: the time in microseconds, or ''. ARGV[2]: '1' to release
     * every count at the end. Then for attempt i, from 1: ARGV[3i] max attempts,
     * ARGV[3i + 1] window in seconds, ARGV[3i + 2] '1' when the rule enforces.
     *
     * Stops at the first enforcing rejection, and then, or with ARGV[2], releases
     * every count it made as the release script does. Returns five integers per
     * attempt made: count()'s four, then 1 when its count was released.
     */
    private const string BATCH_SCRIPT = self::FUNCTIONS.<<<'LUA'
        local now = time_from(ARGV[1])
        local reply = {}
        local counted = {}
        local rejected = false

        for i = 1, #KEYS do
            local allowed, estimate, retry_after, index = count(KEYS[i], tonumber(ARGV[3 * i]), tonumber(ARGV[3 * i + 1]), now)
            local at = 5 * (i - 1)
            reply[at + 1] = allowed
            reply[at + 2] = estimate
            reply[at + 3] = retry_after
            reply[at + 4] = index
            reply[at + 5] = 0

            if allowed == 1 then
                counted[#counted + 1] = {i, index}
            elseif ARGV[3 * i + 2] == '1' then
                rejected = true
                break
            end
        end

        if rejected or ARGV[2] == '1' then
            for _, attempt in ipairs(counted) do
                local key = KEYS[attempt[1]]
                local field = string.format('%d', attempt[2])
                local current = tonumber(redis.call('HGET', key, field))
                if current and current > 0 then
                    redis.call('HINCRBY', key, field, -1)
                end
                reply[5 * attempt[1]] = 1
            end
        end

        return reply
        LUA;

    /**
     * KEYS[1]: the counter's hash. ARGV[1]: the window index the attempt was counted in.
     * Decrements that window's field when it holds a count above 0, so it never
     * creates a field or a key, and leaves the TTL alone (SPEC-004). Returns 1 when
     * it released a count, 0 otherwise.
     */
    private const string RELEASE_SCRIPT = <<<'LUA'
        local count = tonumber(redis.call('HGET', KEYS[1], ARGV[1]))
        if count and count > 0 then
            redis.call('HINCRBY', KEYS[1], ARGV[1], -1)
            return 1
        end
        return 0
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

        $reply = $this->integers($this->run(self::SCRIPT, 'count', [$this->keys->counterBase($key, $windowSeconds)], [
            (string) $maxAttempts,
            (string) $windowSeconds,
            $this->time(),
        ]));

        if ($reply === null || count($reply) !== 4) {
            throw StorageUnavailableException::because('The Redis limiter store returned an unexpected reply.');
        }

        return $this->result($key, $maxAttempts, $windowSeconds, $reply);
    }

    public function attemptAll(array $attempts, bool $releaseAll): AttemptBatch
    {
        // The checks each attempt makes, before anything is counted (SPEC-002 AC-16).
        foreach ($attempts as $planned) {
            AttemptGuard::check($planned->maxAttempts, $planned->windowSeconds);
        }

        if ($attempts === []) {
            return new AttemptBatch;
        }

        try {
            $connection = $this->redis->connection($this->connection);
        } catch (Throwable $exception) {
            return new AttemptBatch(failure: StorageUnavailableException::because('The Redis limiter store could not count the request.', $exception));
        }

        // A script may only touch keys in one hash slot on Redis Cluster (ADR-0005).
        if ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection) {
            return (new OneCallPerAttempt($this))->attemptAll($attempts, $releaseAll);
        }

        $keys = [];
        $arguments = [$this->time(), $releaseAll ? '1' : '0'];

        foreach ($attempts as $planned) {
            $keys[] = $this->keys->counterBase($planned->key, $planned->windowSeconds);
            $arguments[] = (string) $planned->maxAttempts;
            $arguments[] = (string) $planned->windowSeconds;
            $arguments[] = $planned->enforces ? '1' : '0';
        }

        try {
            return $this->batch($attempts, $this->run(self::BATCH_SCRIPT, 'count', $keys, $arguments, $connection));
        } catch (StorageUnavailableException $exception) {
            // Nothing is known about the counts, so none is released: stricter, never looser.
            return new AttemptBatch(failure: $exception);
        }
    }

    public function release(Attempt $attempt): void
    {
        // A rejected attempt was never counted.
        if (! $attempt->allowed) {
            return;
        }

        $reply = $this->run(self::RELEASE_SCRIPT, 'release', [$this->keys->counterBase($attempt->key, $attempt->windowSeconds)], [
            (string) $attempt->windowIndex,
        ]);

        if (! is_int($reply)) {
            throw StorageUnavailableException::because('The Redis limiter store returned an unexpected reply.');
        }
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
     * The SHA-1 that EVALSHA uses for the release script.
     *
     * @internal
     */
    public static function releaseScriptSha(): string
    {
        return hash('sha1', self::RELEASE_SCRIPT);
    }

    /**
     * The SHA-1 that EVALSHA uses for the batch script.
     *
     * @internal
     */
    public static function batchScriptSha(): string
    {
        return hash('sha1', self::BATCH_SCRIPT);
    }

    /**
     * The time argument: the test clock's, or '' for Redis TIME.
     */
    private function time(): string
    {
        return $this->testClock instanceof Clock ? (string) $this->testClock->microseconds() : '';
    }

    /**
     * @param  list<int>  $reply  count()'s allowed, estimate, retry after and window index
     */
    private function result(CounterKey $key, int $maxAttempts, int $windowSeconds, array $reply): Attempt
    {
        [$allowed, $estimate, $retryAfter, $windowIndex] = $reply;

        return new Attempt(
            key: $key,
            limit: $maxAttempts,
            windowSeconds: $windowSeconds,
            windowIndex: $windowIndex,
            allowed: $allowed === 1,
            remaining: $allowed === 1 ? $maxAttempts - $estimate : 0,
            retryAfter: $retryAfter,
        );
    }

    /**
     * A script's reply, when it is a list of integers.
     *
     * @return list<int>|null
     */
    private function integers(mixed $reply): ?array
    {
        if (! is_array($reply) || ! array_is_list($reply)) {
            return null;
        }

        $integers = array_values(array_filter($reply, is_int(...)));

        return count($integers) === count($reply) ? $integers : null;
    }

    /**
     * The batch script's reply: five integers per attempt made, at least one
     * attempt, never more than planned, and fewer only up to an enforcing
     * rejection.
     *
     * @param  non-empty-list<PlannedAttempt>  $attempts
     *
     * @throws StorageUnavailableException for any other reply
     */
    private function batch(array $attempts, mixed $reply): AttemptBatch
    {
        $reply = $this->integers($reply);

        if ($reply === null || $reply === [] || count($reply) % 5 !== 0 || count($reply) > 5 * count($attempts)) {
            throw StorageUnavailableException::because('The Redis limiter store returned an unexpected reply.');
        }

        $made = [];
        $released = [];

        foreach (array_chunk($reply, 5) as $index => [$allowed, $estimate, $retryAfter, $windowIndex, $wasReleased]) {
            $planned = $attempts[$index];
            $made[] = $this->result($planned->key, $planned->maxAttempts, $planned->windowSeconds, [$allowed, $estimate, $retryAfter, $windowIndex]);
            $released[] = $wasReleased === 1;
        }

        $last = count($made) - 1;

        if ($last < count($attempts) - 1 && ($made[$last]->allowed || ! $attempts[$last]->enforces)) {
            throw StorageUnavailableException::because('The Redis limiter store returned an unexpected reply.');
        }

        return new AttemptBatch($made, $released);
    }

    /**
     * Runs the script with EVALSHA and falls back to EVAL when Redis no longer
     * has it (NOSCRIPT, after a restart or failover). Both go through the
     * connection's command(), which rebuilds a phpredis client after a lost
     * connection (AC-16), but not through Laravel's phpredis evalsha(), which
     * sends SCRIPT LOAD on every call.
     *
     * @param  'count'|'release'  $operation  for the message when Redis cannot be used
     * @param  non-empty-list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function run(string $script, string $operation, array $keys, array $arguments, ?Connection $connection = null): mixed
    {
        try {
            $connection ??= $this->redis->connection($this->connection);

            return match (true) {
                $connection instanceof PhpRedisConnection => $this->runWithPhpRedis($connection, $script, $keys, $arguments),
                $connection instanceof PredisConnection => $this->runWithPredis($connection, $script, $keys, $arguments),
                default => throw new InvalidArgumentException('Unsupported Redis connection '.get_debug_type($connection).'; use phpredis or predis.'),
            };
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because("The Redis limiter store could not {$operation} the request.", $exception);
        }
    }

    /**
     * phpredis answers an error with false and keeps the message as its last error.
     *
     * @param  non-empty-list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function runWithPhpRedis(PhpRedisConnection $connection, string $script, array $keys, array $arguments): mixed
    {
        $parameters = [...$keys, ...$arguments];
        $reply = $connection->command('evalsha', [hash('sha1', $script), $parameters, count($keys)]);

        if ($reply === false && str_starts_with($this->lastError($connection), 'NOSCRIPT')) {
            $reply = $connection->command('eval', [$script, $parameters, count($keys)]);
        }

        if ($reply === false) {
            throw StorageUnavailableException::because('Redis rejected the limiter script: '.$this->lastError($connection));
        }

        return $reply;
    }

    private function lastError(PhpRedisConnection $connection): string
    {
        // Read after each command: command() replaces the client after a lost connection.
        $client = $connection->client();

        if (! $client instanceof Redis && ! $client instanceof RedisCluster) {
            throw new InvalidArgumentException('Unsupported phpredis client '.get_debug_type($client).'.');
        }

        return (string) $client->getLastError();
    }

    /**
     * @param  non-empty-list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function runWithPredis(PredisConnection $connection, string $script, array $keys, array $arguments): mixed
    {
        $parameters = [count($keys), ...$keys, ...$arguments];

        try {
            $reply = $connection->command('evalsha', [hash('sha1', $script), ...$parameters]);
        } catch (PredisServerException $exception) {
            if ($exception->getErrorType() !== 'NOSCRIPT') {
                throw $exception;
            }

            $reply = null;
        }

        // Without the "exceptions" option, predis returns errors instead of throwing them.
        if ($reply === null || ($reply instanceof PredisError && $reply->getErrorType() === 'NOSCRIPT')) {
            $reply = $connection->command('eval', [$script, ...$parameters]);
        }

        if ($reply instanceof PredisError) {
            throw StorageUnavailableException::because('Redis rejected the limiter script: '.$reply->getMessage());
        }

        return $reply;
    }
}
