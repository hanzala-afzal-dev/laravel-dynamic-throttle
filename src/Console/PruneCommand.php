<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Support\TableMissing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Str;
use Throwable;

/**
 * Deletes the hourly metrics of hours that ended more than --hours hours ago
 * (ADR-0013 point 5, SPEC-015). The scheduler runs it daily, with
 * telemetry.retention_hours.
 */
final class PruneCommand extends Command
{
    /**
     * The most hours it keeps, about 114 years: more would take the cutoff past what a
     * date can count back, into the future, and delete every row (the review).
     */
    public const int MAX_HOURS = 1_000_000;

    protected $signature = 'dynamic-throttle:prune
        {--hours=720 : Keep the hours that ended within this many hours}';

    protected $description = 'Delete the hourly metrics of hours that ended more than --hours hours ago';

    public function handle(): int
    {
        $given = $this->option('hours');
        $hours = filter_var($given, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($hours)) {
            $this->error(Printable::text('--hours must be a whole number of at least 1, got "'.(is_scalar($given) ? (string) $given : '').'".'));

            return self::FAILURE;
        }

        if ($hours > self::MAX_HOURS) {
            $this->error('--hours can be at most '.self::MAX_HOURS.", about 114 years, got \"{$hours}\".");

            return self::FAILURE;
        }

        $model = new HourlyMetric;
        $before = Date::now('UTC')->subHours($hours);

        try {
            if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
                $this->error(TableMissing::named($model->getTable())->getMessage());

                return self::FAILURE;
            }

            // An hour ended before then when it started more than an hour before.
            $deleted = $model->getConnection()->table($model->getTable())
                ->where('hour', '<', $before->copy()->subHour()->format($model->getDateFormat()))
                ->delete();
        } catch (Throwable $exception) {
            report($exception);
            $this->error(Printable::text('The metrics could not be pruned: '.$exception->getMessage()));

            return self::FAILURE;
        }

        $this->info("Deleted {$deleted} hourly metric ".Str::plural('row', $deleted).' of hours that ended before '.$before->format('Y-m-d H:i').' UTC.');

        return self::SUCCESS;
    }
}
