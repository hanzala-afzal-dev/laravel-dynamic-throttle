<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
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
 * Telemetry on Redis and Valkey (SPEC-015 decisions 4 and 5): one hash per minute,
 * with a field per rule and metric, and per rule and hour the offenders in a
 * sorted set and the distinct clients in a HyperLogLog. Per hour too, the
 * traffic: each request's client in a sorted set, and a hash that counts the
 * requests (SPEC-016 decision 16). A request's numbers are one script call, or
 * one per key on Redis Cluster, where a script may only touch keys in one hash
 * slot (AC-3).
 *
 * Everything runs in Lua, reads included: a script's reply never goes through a
 * phpredis serializer, which would read the counts back wrong.
 *
 * @internal
 */
final readonly class RedisTelemetryBuffer implements TelemetryBuffer
{
    /**
     * Writes keys of three kinds. ARGV[1]: the weight. ARGV[2]: the TTL in seconds.
     * Then for KEYS[i], ARGV[2i + 1] is its kind and ARGV[2i + 2] its argument:
     * - "h", a hash, and the fields to add the weight to, separated by spaces: a
     *   minute's numbers, or an hour's requests;
     * - "o", a sorted set of clients, and the client: a rule's offenders in an hour,
     *   or the hour's traffic. A new client that would make the set 201 first cuts
     *   it back to the top 100, so it stays small, and a new heavy hitter can still
     *   climb into the top (AC-4);
     * - "u", a rule's distinct clients in an hour, and the client;
     * - "l", a minute's live feed, and "TTL SIZE" then its entries, a line each:
     *   pushed so the last is first, cut to SIZE, and given its own TTL (SPEC-030).
     * A key gets its TTL when it is created, so a later write never pushes it back.
     * Returns how many keys it wrote.
     */
    private const string RECORD_SCRIPT = <<<'LUA'
        for i, key in ipairs(KEYS) do
            local kind, argument = ARGV[2 * i + 1], ARGV[2 * i + 2]
            if kind == 'h' then
                for field in string.gmatch(argument, '%S+') do
                    redis.call('HINCRBY', key, field, ARGV[1])
                end
            elseif kind == 'o' then
                if not redis.call('ZSCORE', key, argument) and redis.call('ZCARD', key) >= 200 then
                    redis.call('ZREMRANGEBYRANK', key, 0, -101)
                end
                redis.call('ZINCRBY', key, ARGV[1], argument)
            elseif kind == 'l' then
                local ttl, size, entries = string.match(argument, '^(%d+) (%d+)\n(.*)$')
                for entry in string.gmatch(entries, '[^\n]+') do
                    redis.call('LPUSH', key, entry)
                end
                redis.call('LTRIM', key, 0, tonumber(size) - 1)
                if redis.call('TTL', key) == -1 then
                    redis.call('EXPIRE', key, ttl)
                end
            else
                redis.call('PFADD', key, argument)
            end
            if redis.call('TTL', key) == -1 then
                redis.call('EXPIRE', key, ARGV[2])
            end
        end
        return #KEYS
        LUA;

    /**
     * KEYS: minutes' feeds, newest first. ARGV[1]: how many entries to read from each.
     * Returns their entries, in turn.
     */
    private const string FEED_SCRIPT = <<<'LUA'
        local entries = {}
        for _, key in ipairs(KEYS) do
            for _, entry in ipairs(redis.call('LRANGE', key, 0, tonumber(ARGV[1]) - 1)) do
                entries[#entries + 1] = entry
            end
        end
        return entries
        LUA;

    /** KEYS[1]: a minute's hash. Returns its fields and values, in turn. */
    private const string COUNTS_SCRIPT = <<<'LUA'
        return redis.call('HGETALL', KEYS[1])
        LUA;

    /**
     * KEYS[1]: a sorted set of clients, a rule's offenders or an hour's traffic.
     * ARGV[1]: how many. Returns the clients and their counts, in turn, most first.
     */
    private const string TOP_SCRIPT = <<<'LUA'
        return redis.call('ZREVRANGE', KEYS[1], 0, tonumber(ARGV[1]) - 1, 'WITHSCORES')
        LUA;

    /** KEYS[1]: an hour's requests. Returns their count, 0 when there are none. */
    private const string REQUESTS_SCRIPT = <<<'LUA'
        return tonumber(redis.call('HGET', KEYS[1], 'requests') or '0')
        LUA;

    /** KEYS[1]: a rule's distinct clients in an hour. Returns the estimate, 0 when there are none. */
    private const string CLIENTS_SCRIPT = <<<'LUA'
        return redis.call('PFCOUNT', KEYS[1])
        LUA;

    /** KEYS[1]: the last minute flushed. Returns it, or '' when it is not known: no script returns nil, which phpredis gives as false, as it does an error. */
    private const string FLUSHED_SCRIPT = <<<'LUA'
        return redis.call('GET', KEYS[1]) or ''
        LUA;

    /** KEYS[1]: the last minute flushed. ARGV[1]: the minute. ARGV[2]: the TTL in seconds. */
    private const string MARK_FLUSHED_SCRIPT = <<<'LUA'
        redis.call('SET', KEYS[1], ARGV[1], 'EX', ARGV[2])
        return 1
        LUA;

    public function __construct(
        private RedisFactory $redis,
        /** The Redis connection name, the counters' own. */
        public string $connection,
        private KeyBuilder $keys,
    ) {}

    public function record(int $minute, int $weight, array $records, ?string $client = null, array $feed = []): void
    {
        if ($weight < 1) {
            throw new InvalidArgumentException("A request stands for at least 1 request, got {$weight}.");
        }

        if ($records === []) {
            return;
        }

        $hour = intdiv($minute, 60);
        $fields = [];
        $sets = [];
        $setArguments = [];

        foreach ($records as $record) {
            foreach ($this->metrics($record) as $metric) {
                $fields[] = "{$record->ruleId}:{$metric->value}";
            }

            if ($record->outcome !== TelemetryOutcome::Blocked && $record->outcome !== TelemetryOutcome::WouldBlock) {
                continue;
            }

            $sets[] = $this->keys->offenders($record->ruleId, $hour);
            $sets[] = $this->keys->clients($record->ruleId, $hour);
            $setArguments[] = 'o';
            $setArguments[] = $record->identityHash;
            $setArguments[] = 'u';
            $setArguments[] = $record->identityHash;
        }

        // The hour's traffic: the client, and one more request (SPEC-016 decision 16).
        if ($client !== null) {
            $sets[] = $this->keys->traffic($hour);
            $sets[] = $this->keys->requests($hour);
            $setArguments[] = 'o';
            $setArguments[] = $client;
            $setArguments[] = 'h';
            $setArguments[] = 'requests';
        }

        // The live feed: the request's blocks and would-blocks, in the same call (SPEC-030 decision 2).
        if ($feed !== []) {
            $sets[] = $this->keys->feed($minute);
            $setArguments[] = 'l';
            $setArguments[] = self::FEED_TTL_SECONDS.' '.self::FEED_SIZE."\n".implode("\n", array_map(fn (FeedEntry $entry): string => $entry->toJson(), $feed));
        }

        $keys = [$this->keys->telemetryMinute($minute), ...$sets];
        $arguments = [(string) $weight, (string) self::TTL_SECONDS, 'h', implode(' ', $fields), ...$setArguments];

        try {
            $connection = $this->redis->connection($this->connection);
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The Redis telemetry buffer could not record the request.', $exception);
        }

        if (! $connection instanceof PhpRedisClusterConnection && ! $connection instanceof PredisClusterConnection) {
            $this->wrote(count($keys), $this->run(self::RECORD_SCRIPT, 'record the request', $keys, $arguments));

            return;
        }

        // Each key with its own kind and argument (ADR-0005, as SPEC-027's counting does).
        foreach ($keys as $index => $key) {
            $this->wrote(1, $this->run(self::RECORD_SCRIPT, 'record the request', [$key], [$arguments[0], $arguments[1], $arguments[2 * $index + 2], $arguments[2 * $index + 3]]));
        }
    }

    public function feed(int $minute, ?int $since = null): array
    {
        // The minutes a read covers, newest first, from the one $since falls in when it is later.
        $oldest = max($minute - self::FEED_MINUTES + 1, $since === null ? PHP_INT_MIN : intdiv($since, 60_000));
        $keys = array_map($this->keys->feed(...), $oldest > $minute ? [] : range($minute, $oldest));
        $lines = [];

        try {
            $connection = $this->redis->connection($this->connection);
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because('The Redis telemetry buffer could not read the feed.', $exception);
        }

        // One call outside Redis Cluster, and one per key on it (AC-6).
        foreach ($connection instanceof PhpRedisClusterConnection || $connection instanceof PredisClusterConnection ? array_map(fn (string $key): array => [$key], $keys) : [$keys] as $batch) {
            $reply = $batch === [] ? [] : $this->run(self::FEED_SCRIPT, 'read the feed', $batch, [(string) self::FEED_SIZE]);

            if (! is_array($reply)) {
                throw $this->unexpectedReply();
            }

            array_push($lines, ...$reply);
        }

        $entries = [];

        foreach ($lines as $line) {
            $entry = is_string($line) ? FeedEntry::fromJson($line) : null;

            if ($entry instanceof FeedEntry && ($since === null || $entry->time > $since)) {
                $entries[] = $entry;
            }
        }

        // Newest first across minutes and servers; entries of one time keep the order Redis gave.
        usort($entries, fn (FeedEntry $a, FeedEntry $b): int => $b->time <=> $a->time);

        return array_slice($entries, 0, self::FEED_SIZE);
    }

    /**
     * Every rule the minute holds, whatever $ruleIds says: the hash lists them, the
     * rules deleted since included (AC-15).
     */
    public function counts(int $minute, array $ruleIds): array
    {
        $reply = $this->run(self::COUNTS_SCRIPT, 'read the minute', [$this->keys->telemetryMinute($minute)]);
        $values = [];

        foreach ($this->pairs($reply) as [$field, $value]) {
            $separator = strrpos($field, ':');
            $metric = TelemetryMetric::tryFrom(substr($field, (int) $separator + 1));

            if ($separator === false || ! $metric instanceof TelemetryMetric || ! is_numeric($value)) {
                throw $this->unexpectedReply();
            }

            $values[substr($field, 0, $separator)][$metric->value] = max(0, (int) $value);
        }

        $counts = [];

        foreach ($values as $ruleId => $metrics) {
            $counts[$ruleId] = new MinuteCounts(
                $metrics[TelemetryMetric::Hits->value] ?? 0,
                $metrics[TelemetryMetric::Blocks->value] ?? 0,
                $metrics[TelemetryMetric::WouldBlocks->value] ?? 0,
                $metrics[TelemetryMetric::Guests->value] ?? 0,
                $metrics[TelemetryMetric::Skipped->value] ?? 0,
                $metrics[TelemetryMetric::Ignored->value] ?? 0,
            );
        }

        return $counts;
    }

    public function offenders(string $ruleId, int $hour, int $limit = self::OFFENDERS): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The offenders to read must be at least 1, got {$limit}.");
        }

        return $this->top($this->keys->offenders($ruleId, $hour), $limit, 'read the offenders');
    }

    public function clients(string $ruleId, int $hour): int
    {
        $reply = $this->run(self::CLIENTS_SCRIPT, 'count the clients', [$this->keys->clients($ruleId, $hour)]);

        if (! is_int($reply)) {
            throw $this->unexpectedReply();
        }

        return $reply;
    }

    public function topClients(int $hour, int $limit = 1): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException("The top clients to read must be at least 1, got {$limit}.");
        }

        return $this->top($this->keys->traffic($hour), $limit, 'read the top clients');
    }

    public function requests(int $hour): int
    {
        $reply = $this->run(self::REQUESTS_SCRIPT, 'count the requests', [$this->keys->requests($hour)]);

        if (! is_int($reply)) {
            throw $this->unexpectedReply();
        }

        return $reply;
    }

    public function flushedThrough(): ?int
    {
        $reply = $this->run(self::FLUSHED_SCRIPT, 'read the last minute flushed', [$this->keys->telemetryFlushed()]);

        return is_numeric($reply) ? (int) $reply : null;
    }

    /**
     * Kept as long as the numbers: once they expire, there is nothing before the
     * flush's window to tell it about.
     */
    public function markFlushed(int $minute): void
    {
        $this->run(self::MARK_FLUSHED_SCRIPT, 'keep the last minute flushed', [$this->keys->telemetryFlushed()], [(string) $minute, (string) self::TTL_SECONDS]);
    }

    /**
     * The metrics a record adds its weight to: its outcome's, and its rule's guests
     * when a user rule counted a guest (SPEC-016).
     *
     * @return list<TelemetryMetric>
     */
    private function metrics(TelemetryRecord $record): array
    {
        $metrics = match ($record->outcome) {
            TelemetryOutcome::Counted => [TelemetryMetric::Hits],
            TelemetryOutcome::Blocked => [TelemetryMetric::Hits, TelemetryMetric::Blocks],
            TelemetryOutcome::WouldBlock => [TelemetryMetric::Hits, TelemetryMetric::WouldBlocks],
            TelemetryOutcome::Skipped => [TelemetryMetric::Skipped],
            TelemetryOutcome::Ignored => [TelemetryMetric::Ignored],
        };

        return $record->guest ? [...$metrics, TelemetryMetric::Guests] : $metrics;
    }

    /**
     * The clients of a sorted set with the most requests, most first.
     *
     * @return array<string, int>
     *
     * @throws StorageUnavailableException
     */
    private function top(string $key, int $limit, string $operation): array
    {
        $reply = $this->run(self::TOP_SCRIPT, $operation, [$key], [(string) $limit]);
        $top = [];

        foreach ($this->pairs($reply) as [$client, $count]) {
            if (! is_numeric($count)) {
                throw $this->unexpectedReply();
            }

            $top[$client] = (int) $count;
        }

        return $top;
    }

    /**
     * @throws StorageUnavailableException unless the record script says it wrote every key it was given
     */
    private function wrote(int $keys, mixed $reply): void
    {
        if ($reply !== $keys) {
            throw $this->unexpectedReply();
        }
    }

    private function unexpectedReply(): StorageUnavailableException
    {
        return StorageUnavailableException::because('The Redis telemetry buffer returned an unexpected reply.');
    }

    /**
     * A reply of fields and values in turn, as pairs.
     *
     * @return list<array{string, mixed}>
     *
     * @throws StorageUnavailableException for any other reply
     */
    private function pairs(mixed $reply): array
    {
        if (! is_array($reply) || ! array_is_list($reply) || count($reply) % 2 !== 0) {
            throw $this->unexpectedReply();
        }

        $pairs = [];

        foreach (array_chunk($reply, 2) as [$field, $value]) {
            if (! is_string($field)) {
                throw $this->unexpectedReply();
            }

            $pairs[] = [$field, $value];
        }

        return $pairs;
    }

    /**
     * Runs a script with EVALSHA, and with EVAL when Redis does not have it yet
     * (NOSCRIPT), as RedisLimiterStore runs its own, through the connection's
     * command().
     *
     * @param  string  $operation  what the script does, for the failure's message
     * @param  non-empty-list<string>  $keys
     * @param  list<string>  $arguments
     *
     * @throws StorageUnavailableException when Redis cannot run it
     */
    private function run(string $script, string $operation, array $keys, array $arguments = []): mixed
    {
        try {
            $connection = $this->redis->connection($this->connection);
            // Redis requires SHA-1 here; it is a cache key, not a security measure.
            $sha = hash('sha1', $script);

            return match (true) {
                $connection instanceof PhpRedisConnection => $this->runWithPhpRedis($connection, $script, $sha, $keys, $arguments),
                $connection instanceof PredisConnection => $this->runWithPredis($connection, $script, $sha, $keys, $arguments),
                default => throw new InvalidArgumentException('Unsupported Redis connection '.get_debug_type($connection).'; use phpredis or predis.'),
            };
        } catch (Throwable $exception) {
            throw StorageUnavailableException::because("The Redis telemetry buffer could not {$operation}.", $exception);
        }
    }

    /**
     * phpredis answers an error with false and keeps the message as its last error.
     *
     * @param  non-empty-list<string>  $keys
     * @param  list<string>  $arguments
     */
    private function runWithPhpRedis(PhpRedisConnection $connection, string $script, string $sha, array $keys, array $arguments): mixed
    {
        $parameters = [...$keys, ...$arguments];
        $reply = $connection->command('evalsha', [$sha, $parameters, count($keys)]);

        if ($reply === false && str_starts_with($this->lastError($connection), 'NOSCRIPT')) {
            $reply = $connection->command('eval', [$script, $parameters, count($keys)]);
        }

        if ($reply === false) {
            throw StorageUnavailableException::because('Redis rejected the telemetry script: '.$this->lastError($connection));
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
    private function runWithPredis(PredisConnection $connection, string $script, string $sha, array $keys, array $arguments): mixed
    {
        $parameters = [count($keys), ...$keys, ...$arguments];

        try {
            $reply = $connection->command('evalsha', [$sha, ...$parameters]);
        } catch (PredisServerException $exception) {
            if ($exception->getErrorType() !== 'NOSCRIPT') {
                throw $exception;
            }

            $reply = $connection->command('eval', [$script, ...$parameters]);
        }

        // Without the "exceptions" option, predis returns errors instead of throwing them.
        if ($reply instanceof PredisError && $reply->getErrorType() === 'NOSCRIPT') {
            $reply = $connection->command('eval', [$script, ...$parameters]);
        }

        if ($reply instanceof PredisError) {
            throw StorageUnavailableException::because('Redis rejected the telemetry script: '.$reply->getMessage());
        }

        return $reply;
    }
}
