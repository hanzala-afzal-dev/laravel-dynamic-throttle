<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Illuminate\Support\Facades\DB;

/**
 * Telemetry's buffer and table, as SPEC-015's tests read them.
 */
final class Telemetry
{
    public const string TABLE = 'dynamic_throttle_metrics_hourly';

    /**
     * Unix minutes at a Unix time.
     */
    public static function minute(int $seconds): int
    {
        return intdiv($seconds, 60);
    }

    /**
     * Unix hours at a Unix time.
     */
    public static function hour(int $seconds): int
    {
        return intdiv($seconds, 3_600);
    }

    /**
     * The buffer the app records into.
     */
    public static function buffer(): TelemetryBuffer
    {
        return app(TelemetryBuffer::class);
    }

    /**
     * A minute's numbers as [hits, blocks, would_blocks], by rule id.
     *
     * @param  list<string>  $ruleIds
     * @return array<string, array{int, int, int}>
     */
    public static function counts(int $minute, array $ruleIds, ?TelemetryBuffer $buffer = null): array
    {
        return array_map(
            fn (MinuteCounts $counts): array => [$counts->hits, $counts->blocks, $counts->wouldBlocks],
            ($buffer ?? self::buffer())->counts($minute, $ruleIds),
        );
    }

    public static function counted(string $ruleId, string $client = 'A'): TelemetryRecord
    {
        return new TelemetryRecord($ruleId, hash('sha256', $client), TelemetryOutcome::Counted);
    }

    public static function blocked(string $ruleId, string $client = 'A'): TelemetryRecord
    {
        return new TelemetryRecord($ruleId, hash('sha256', $client), TelemetryOutcome::Blocked);
    }

    public static function wouldBlock(string $ruleId, string $client = 'A'): TelemetryRecord
    {
        return new TelemetryRecord($ruleId, hash('sha256', $client), TelemetryOutcome::WouldBlock);
    }

    /**
     * The table's rows, by rule and hour, as [rule_id, hour, hits, blocks, would_blocks, clients].
     *
     * @return list<array{int, string, int, int, int, ?int}>
     */
    public static function rows(): array
    {
        return DB::table(self::TABLE)->orderBy('rule_id')->orderBy('hour')->get()->map(fn (object $row): array => [
            (int) $row->rule_id,
            (string) $row->hour,
            (int) $row->hits,
            (int) $row->blocks,
            (int) $row->would_blocks,
            $row->clients === null ? null : (int) $row->clients,
        ])->values()->all();
    }
}
