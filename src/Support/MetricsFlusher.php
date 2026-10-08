<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Moves telemetry's complete minutes from the counter store into the hourly rows
 * (ADR-0013 point 4, SPEC-015), for dynamic-throttle:flush-metrics.
 *
 * It reads each minute after the last one flushed, up to the last complete one,
 * and never further back than the store keeps them. Each row remembers the last
 * minute added to it, and a minute is added only where that is earlier, in one
 * statement, so a run that is repeated, that stopped halfway, or that runs at the
 * same time as another adds each minute once (decision 8). The store's numbers
 * are never deleted: they expire.
 *
 * @internal
 */
final readonly class MetricsFlusher
{
    /** How far back a flush reads, in minutes: as long as the store keeps the numbers (ADR-0013 point 2). */
    public const int WINDOW_MINUTES = 120;

    public function __construct(
        private TelemetryBuffer $buffer,
        private Clock $clock,
    ) {}

    /**
     * The last complete minute, in Unix minutes. A request writes into the minute it
     * ends in, after its response, so a minute is complete once the next one has
     * ended too.
     */
    public function lastCompleteMinute(): int
    {
        return intdiv($this->clock->microseconds(), 60_000_000) - 2;
    }

    /**
     * @return list<int> the minutes read, in Unix minutes, oldest first: none when every complete minute was flushed
     *
     * @throws TableMissing with nothing read or written
     * @throws StorageUnavailableException|Throwable from the store or the database: the next run catches up
     */
    public function flush(): array
    {
        $model = new HourlyMetric;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            throw TableMissing::named($model->getTable());
        }

        $through = $this->lastCompleteMinute();
        $flushed = $this->buffer->flushedThrough();
        $from = max($through + 2 - self::WINDOW_MINUTES, $flushed === null ? PHP_INT_MIN : $flushed + 1);

        if ($from > $through) {
            return [];
        }

        // A cache store cannot list its keys, so its buffer reads the rules in the table (AC-15).
        $ruleIds = [];

        foreach (Rule::query()->pluck('id') as $id) {
            if (is_int($id) || is_string($id)) {
                $ruleIds[] = (string) $id;
            }
        }
        $minutes = range($from, $through);
        $rows = [];

        foreach ($minutes as $minute) {
            foreach ($this->buffer->counts($minute, $ruleIds) as $ruleId => $counts) {
                $ruleId = (string) $ruleId;

                // Not a rule's id, so not a row's. A minute without hits holds only numbers
                // the table does not keep, of a rule that was skipped or ignored (SPEC-016 AC-20).
                if (! ctype_digit($ruleId) || $counts->hits === 0) {
                    continue;
                }

                $hour = intdiv($minute, 60);
                $this->add($ruleId, $hour, $minute, $counts);
                $rows["{$ruleId}:{$hour}"] = [$ruleId, $hour];
            }
        }

        foreach ($rows as [$ruleId, $hour]) {
            $clients = $this->buffer->clients($ruleId, $hour);

            if ($clients !== null) {
                $this->setClients($ruleId, $hour, $clients);
            }
        }

        $this->buffer->markFlushed($through);

        return $minutes;
    }

    /**
     * Adds a minute to its rule's row for the hour, which it creates when it is
     * missing, unless the row has taken it in already. Plain updates and inserts,
     * which every database Laravel supports compiles: SQL Server has no insert that
     * ignores a duplicate (the review).
     */
    private function add(string $ruleId, int $hour, int $minute, MinuteCounts $counts): void
    {
        if ($this->addTo($ruleId, $hour, $minute, $counts) || $this->row($ruleId, $hour)->exists()) {
            return;
        }

        try {
            $this->table()->insert([
                'rule_id' => (int) $ruleId,
                'hour' => $this->hour($hour),
                'hits' => $counts->hits,
                'blocks' => $counts->blocks,
                'would_blocks' => $counts->wouldBlocks,
                'clients' => null,
                'flushed_minute' => $minute,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Another flush created the row since the check.
            $this->addTo($ruleId, $hour, $minute, $counts);
        }
    }

    /**
     * @return bool whether the row took the minute in now: false when it is missing, or took it in already
     */
    private function addTo(string $ruleId, int $hour, int $minute, MinuteCounts $counts): bool
    {
        return $this->row($ruleId, $hour)
            ->where('flushed_minute', '<', $minute)
            ->incrementEach(['hits' => $counts->hits, 'blocks' => $counts->blocks, 'would_blocks' => $counts->wouldBlocks], ['flushed_minute' => $minute]) > 0;
    }

    /**
     * Sets a row's distinct clients to the hour's count, which only grows: never added,
     * since each count is the whole hour's so far (AC-13), and never lowered, since the
     * count expires 2 hours after the hour's first block, before the hour's last
     * minutes do (the review). A count of 0 holds only where nothing was blocked:
     * elsewhere the count expired before the row's first flush, and stays unknown.
     */
    private function setClients(string $ruleId, int $hour, int $clients): void
    {
        $this->row($ruleId, $hour)
            ->where(fn (Builder $row): Builder => $row->whereNull('clients')->orWhere('clients', '<', $clients))
            ->when($clients === 0, fn (Builder $row): Builder => $row->where('blocks', 0)->where('would_blocks', 0))
            ->update(['clients' => $clients]);
    }

    private function row(string $ruleId, int $hour): Builder
    {
        return $this->table()->where('rule_id', (int) $ruleId)->where('hour', $this->hour($hour));
    }

    private function table(): Builder
    {
        $model = new HourlyMetric;

        return $model->getConnection()->table($model->getTable());
    }

    /**
     * An hour's start as the table keeps it: in UTC (decision 9).
     *
     * @param  int  $hour  Unix hours
     */
    private function hour(int $hour): string
    {
        return Date::createFromTimestamp($hour * 3_600, 'UTC')->format((new HourlyMetric)->getDateFormat());
    }
}
