<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\TableMissing;
use Illuminate\Contracts\Validation\Factory as ValidationFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;

/**
 * GET api/metrics (SPEC-017 decision 10): each rule's requests, blocks and
 * would-blocks, per minute for the last 60 minutes from the counter store
 * (period=hour), or per hour for the last 24 hours (day) or 168 hours (week)
 * from the hourly table. Each series ends with the current minute or hour, and
 * lists only the rules with numbers, a rule deleted since with its name null.
 *
 * @internal
 */
final readonly class MetricsController
{
    /** Each period's step in seconds, and how many steps it shows. */
    private const array PERIODS = ['hour' => [60, 60], 'day' => [3_600, 24], 'week' => [3_600, 168]];

    public function __construct(
        private TelemetryBuffer $buffer,
        private Clock $clock,
        private ValidationFactory $validation,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        return ApiErrors::json(function () use ($request): JsonResponse {
            $period = $this->validation->make($request->query(), ['period' => ['sometimes', 'string', 'in:'.implode(',', array_keys(self::PERIODS))]])->validate()['period'] ?? 'hour';
            $period = is_string($period) ? $period : 'hour';
            [$step, $count] = self::PERIODS[$period];
            $current = intdiv(intdiv($this->clock->microseconds(), 1_000_000), $step);
            $first = $current - $count + 1;
            $names = Rule::query()->pluck('name', 'id')->all();
            // At most 500, so a buffer that cannot list its own reads a bounded number of keys (AC-26).
            $enabled = $period === 'hour'
                ? array_values(Rule::query()->where('enabled', true)->orderBy('id')->pluck('id')->map(fn (mixed $id): string => is_scalar($id) ? (string) $id : '')->all())
                : [];

            $numbers = $period === 'hour' ? $this->minutes($first, $count, $enabled) : $this->hours($first, $count);

            return new JsonResponse([
                'period' => $period,
                'step_seconds' => $step,
                'times' => array_map(fn (int $slot): ?string => Payloads::time(Date::createFromTimestamp($slot * $step, 'UTC')), range($first, $current)),
                'rules' => $this->series($numbers, $names, $count),
            ]);
        });
    }

    /**
     * The counter store's minutes, by rule id and minute's index.
     *
     * @param  list<string>  $ruleIds
     * @return array<int, array<int, array{int, int, int}>>
     */
    private function minutes(int $first, int $count, array $ruleIds): array
    {
        $numbers = [];

        for ($index = 0; $index < $count; $index++) {
            foreach ($this->buffer->counts($first + $index, $ruleIds) as $ruleId => $counts) {
                $numbers[(int) $ruleId][$index] = [$counts->hits, $counts->blocks, $counts->wouldBlocks];
            }
        }

        return $numbers;
    }

    /**
     * The hourly table's hours, by rule id and hour's index.
     *
     * @return array<int, array<int, array{int, int, int}>>
     *
     * @throws TableMissing
     */
    private function hours(int $first, int $count): array
    {
        $model = new HourlyMetric;

        if (! $model->getConnection()->getSchemaBuilder()->hasTable($model->getTable())) {
            throw TableMissing::named($model->getTable());
        }

        $rows = HourlyMetric::query()->toBase()
            ->where('hour', '>=', Date::createFromTimestamp($first * 3_600, 'UTC')->format('Y-m-d H:i:s'))
            ->get(['rule_id', 'hour', 'hits', 'blocks', 'would_blocks']);
        $numbers = [];

        foreach ($rows as $row) {
            $index = is_string($row->hour) ? intdiv(Date::parse($row->hour, 'UTC')->getTimestamp(), 3_600) - $first : -1;

            if ($index >= 0 && $index < $count && is_numeric($row->rule_id)) {
                $numbers[(int) $row->rule_id][$index] = [(int) $row->hits, (int) $row->blocks, (int) $row->would_blocks];
            }
        }

        return $numbers;
    }

    /**
     * @param  array<int, array<int, array{int, int, int}>>  $numbers
     * @param  array<int|string, mixed>  $names
     * @return list<array{id: int, name: ?string, hits: list<int>, blocks: list<int>, would_blocks: list<int>}>
     */
    private function series(array $numbers, array $names, int $count): array
    {
        ksort($numbers);
        $series = [];

        foreach ($numbers as $ruleId => $slots) {
            $column = fn (int $metric): array => array_map(fn (int $index): int => $slots[$index][$metric] ?? 0, range(0, $count - 1));
            $name = $names[$ruleId] ?? null;

            $series[] = ['id' => $ruleId, 'name' => is_string($name) ? $name : null, 'hits' => $column(0), 'blocks' => $column(1), 'would_blocks' => $column(2)];
        }

        return $series;
    }
}
