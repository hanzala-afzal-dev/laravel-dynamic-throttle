<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Afzal\DynamicThrottle\Support\MetricsFlusher;
use Afzal\DynamicThrottle\Support\TableMissing;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Date;
use Throwable;

/**
 * Moves telemetry's complete minutes from the counter store into
 * dynamic_throttle_metrics_hourly (ADR-0013 point 4, SPEC-015). The scheduler
 * runs it every minute; running it again, or at the same time on another
 * server, adds nothing twice.
 */
final class FlushMetricsCommand extends Command
{
    protected $signature = 'dynamic-throttle:flush-metrics';

    protected $description = 'Move the complete minutes of telemetry from the counter store into the hourly metrics table';

    public function handle(): int
    {
        try {
            $flusher = $this->laravel->make(MetricsFlusher::class);
            $minutes = $flusher->flush();
        } catch (TableMissing $missing) {
            $this->error($missing->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            // The store keeps the numbers 2 hours, so the next run catches up.
            report($exception);
            $this->error(Printable::text('The metrics could not be flushed: '.$exception->getMessage()));

            return self::FAILURE;
        }

        if ($minutes === []) {
            $this->info('Nothing to flush: the minutes through '.$this->minute($flusher->lastCompleteMinute()).' are flushed.');

            return self::SUCCESS;
        }

        $this->info('Flushed through '.$this->minute($minutes[count($minutes) - 1]).'.');

        return self::SUCCESS;
    }

    /**
     * @param  int  $minute  Unix minutes
     */
    private function minute(int $minute): string
    {
        return Date::createFromTimestamp($minute * 60, 'UTC')->format('Y-m-d H:i').' UTC';
    }
}
