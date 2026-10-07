<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
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

    /**
     * Adds one request's records to a minute.
     *
     * @param  int  $minute  Unix minutes: Unix seconds divided by 60
     * @param  int  $weight  how many requests the request stands for, at least 1 (decision 6)
     * @param  list<TelemetryRecord>  $records
     *
     * @throws StorageUnavailableException
     */
    public function record(int $minute, int $weight, array $records): void;

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
