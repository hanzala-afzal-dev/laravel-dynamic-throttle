<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;

/**
 * Where requests leave their numbers, in the counter store, until the flush moves
 * them to the database (ADR-0013, SPEC-015). Everything it writes expires 2 hours
 * after it is first written.
 *
 * @internal
 */
interface TelemetryBuffer
{
    /** How long the numbers live in the store, in seconds (ADR-0013 point 2). */
    public const int TTL_SECONDS = 7_200;

    /** How many offenders a read gives (ADR-0013 point 3). */
    public const int OFFENDERS = 100;

    /** How long a minute's feed lives, in seconds: an hour and a minute, so no entry outlives it (SPEC-030 decision 1). */
    public const int FEED_TTL_SECONDS = 3_660;

    /** How many entries a minute's feed keeps, and a read gives, at most (SPEC-030). */
    public const int FEED_SIZE = 500;

    /** How many minutes a read of the feed covers, the current one included (SPEC-030). */
    public const int FEED_MINUTES = 60;

    /**
     * Adds one request's records to a minute.
     *
     * @param  int  $minute  Unix minutes: Unix seconds divided by 60
     * @param  int  $weight  how many requests the request stands for, at least 1 (decision 6)
     * @param  list<TelemetryRecord>  $records
     * @param  ?string  $client  the request's client, the `ip` scope's identity hash, for the hour's traffic (SPEC-016)
     * @param  list<FeedEntry>  $feed  the request's blocks and would-blocks for the live feed, which a buffer that keeps none ignores (SPEC-030)
     *
     * @throws StorageUnavailableException
     */
    public function record(int $minute, int $weight, array $records, ?string $client = null, array $feed = []): void;

    /**
     * A minute's numbers, by rule id, for the rules that have any.
     *
     * @param  int  $minute  Unix minutes
     * @param  list<string>  $ruleIds  the rules to read; a buffer that can list its own reads every rule it holds
     * @return array<string, MinuteCounts>
     *
     * @throws StorageUnavailableException
     */
    public function counts(int $minute, array $ruleIds): array;

    /**
     * The live feed's newest entries, newest first: at most FEED_SIZE from the
     * FEED_MINUTES minutes up to $minute, and only those after $since when it is given.
     * Null when the driver keeps no feed (SPEC-030).
     *
     * @param  int  $minute  Unix minutes
     * @param  ?int  $since  Unix milliseconds
     * @return list<FeedEntry>|null
     *
     * @throws StorageUnavailableException
     */
    public function feed(int $minute, ?int $since = null): ?array;

    /**
     * The clients a rule blocked or would have blocked most in an hour, by identity
     * hash, most first, or null when the driver keeps none.
     *
     * @param  int  $hour  Unix hours: Unix seconds divided by 3,600
     * @return array<string, int>|null
     *
     * @throws StorageUnavailableException
     */
    public function offenders(string $ruleId, int $hour, int $limit = self::OFFENDERS): ?array;

    /**
     * How many different clients a rule blocked or would have blocked in an hour, or
     * null when the driver keeps no count.
     *
     * @param  int  $hour  Unix hours
     *
     * @throws StorageUnavailableException
     */
    public function clients(string $ruleId, int $hour): ?int;

    /**
     * The clients that sent the most recorded requests in an hour, by identity hash,
     * most first, or null when the driver keeps none (SPEC-016).
     *
     * @param  int  $hour  Unix hours
     * @return array<string, int>|null
     *
     * @throws StorageUnavailableException
     */
    public function topClients(int $hour, int $limit = 1): ?array;

    /**
     * How many requests were recorded in an hour, or null when the driver keeps no
     * count (SPEC-016).
     *
     * @param  int  $hour  Unix hours
     *
     * @throws StorageUnavailableException
     */
    public function requests(int $hour): ?int;

    /**
     * The last minute the flush moved, or null when it is not known.
     *
     * @throws StorageUnavailableException
     */
    public function flushedThrough(): ?int;

    /**
     * @param  int  $minute  Unix minutes
     *
     * @throws StorageUnavailableException
     */
    public function markFlushed(int $minute): void;
}
