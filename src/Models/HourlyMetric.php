<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Models;

use Afzal\DynamicThrottle\Models\Concerns\UsesPackageConnection;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;

/**
 * One rule's numbers for one hour (ADR-0013 point 4, SPEC-015), which
 * dynamic-throttle:flush-metrics adds to and dynamic-throttle:prune deletes. A row
 * outlives its rule until it is pruned (decision 10).
 *
 * @property int $id
 * @property int $rule_id
 * @property-read CarbonInterface $hour the hour's start, in UTC (decision 9), in the date class the app uses
 * @property int $hits the requests the rule checked
 * @property int $blocks the requests an enforcing rule rejected
 * @property int $would_blocks the requests a dry-run rule would have rejected
 * @property int|null $clients how many different clients those were, null where the driver keeps no count
 * @property int $flushed_minute the last minute added, in Unix minutes
 */
final class HourlyMetric extends Model
{
    use UsesPackageConnection;

    public $timestamps = false;

    protected $table = 'dynamic_throttle_metrics_hourly';

    /**
     * Read in UTC, whatever the app's time zone: in local time, the hour that
     * repeats when daylight saving time ends would be one row (decision 9). The
     * flush writes the rows, so the model only reads them.
     *
     * @return Attribute<CarbonInterface|null, never>
     */
    protected function hour(): Attribute
    {
        return Attribute::get($this->utcHour(...));
    }

    /**
     * In the class Date::use() names, so an app on immutable dates gets them (the review).
     */
    private function utcHour(mixed $value): ?CarbonInterface
    {
        return is_string($value) ? Date::parse($value, 'UTC') : null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'rule_id' => 'integer',
            'hits' => 'integer',
            'blocks' => 'integer',
            'would_blocks' => 'integer',
            'clients' => 'integer',
            'flushed_minute' => 'integer',
        ];
    }
}
