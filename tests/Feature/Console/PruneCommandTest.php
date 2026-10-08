<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-015 AC-17: dynamic-throttle:prune deletes the rows of hours that ended
| more than --hours hours ago. The clock stands at T0, 2027-01-15 08:00:00 UTC.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * A row for rule 1 at the hour that starts $hoursAgo hours before T0.
 */
function metricRow(int $hoursAgo): void
{
    DB::table(Telemetry::TABLE)->insert([
        'rule_id' => 1,
        'hour' => Carbon::createFromTimestamp(FakeClock::T0 - $hoursAgo * 3_600, 'UTC')->format('Y-m-d H:i:s'),
        'hits' => $hoursAgo,
        'blocks' => 0,
        'would_blocks' => 0,
        'clients' => null,
        'flushed_minute' => 0,
    ]);
}

/**
 * @return list<int> the hits of the rows left, which are their hours ago
 */
function metricRowsLeft(): array
{
    return array_map(fn (array $row): int => $row[2], Telemetry::rows());
}

it('AC-17: deletes the rows of hours that ended more than --hours hours ago, and keeps the rest', function (): void {
    // An hour that starts 721 hours ago ended exactly 720 hours ago, so it stays.
    foreach ([1, 720, 721, 722, 900] as $hoursAgo) {
        metricRow($hoursAgo);
    }

    $this->artisan('dynamic-throttle:prune', ['--hours' => 720])
        ->expectsOutput('Deleted 2 hourly metric rows of hours that ended before 2026-12-16 08:00 UTC.')
        ->assertExitCode(0);

    expect(metricRowsLeft())->toBe([721, 720, 1]);
});

it('AC-17: keeps 720 hours, 30 days, without --hours', function (): void {
    metricRow(1);
    metricRow(800);

    $this->artisan('dynamic-throttle:prune')->assertExitCode(0);

    expect(metricRowsLeft())->toBe([1]);
});

it('AC-17: prints why --hours is wrong, and exits 1', function (string $hours): void {
    metricRow(800);

    $this->artisan('dynamic-throttle:prune', ['--hours' => $hours])
        ->expectsOutputToContain("--hours must be a whole number of at least 1, got \"{$hours}\".")
        ->assertExitCode(1);

    expect(metricRowsLeft())->toBe([800]);
})->with([
    'zero' => ['0'],
    'negative' => ['-5'],
    'a fraction' => ['1.5'],
    'a word' => ['week'],
]);

it('AC-17: says how to create the table when it is missing, and exits 1', function (): void {
    Schema::drop(Telemetry::TABLE);

    $this->artisan('dynamic-throttle:prune')
        ->expectsOutputToContain('The dynamic_throttle_metrics_hourly table does not exist.')
        ->assertExitCode(1);
});

it('AC-17: refuses more --hours than it can count back, which would delete every row', function (): void {
    metricRow(1);
    metricRow(48);

    $this->artisan('dynamic-throttle:prune', ['--hours' => '9223372036854775807'])
        ->expectsOutputToContain('--hours can be at most 1000000, about 114 years, got "9223372036854775807".')
        ->assertExitCode(1);
    $this->artisan('dynamic-throttle:prune', ['--hours' => '1000000'])->assertExitCode(0);

    expect(metricRowsLeft())->toBe([48, 1]);
});
