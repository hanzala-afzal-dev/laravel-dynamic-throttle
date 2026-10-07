<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\MetricsFlusher;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RecordingTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Carbon\CarbonImmutable;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\SQLiteGrammar;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-015 AC-10 to AC-16: dynamic-throttle:flush-metrics moves complete
| minutes from the buffer to dynamic_throttle_metrics_hourly. SQLite and the
| cache driver on the array store; rules 1 and 2 exist. T0 is 2027-01-15
| 08:00:00 UTC, so 10:58 is T0 + 2 h 58 min.
*/

beforeEach(function (): void {
    Throttling::prepare();
    Throttling::rule(['name' => 'API']);
    Throttling::rule(['name' => 'Audit']);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Unix seconds at a time of 2027-01-15, in UTC, such as "10:58" or "11:01:30".
 */
function flushAt(string $time): int
{
    return Carbon::parse("2027-01-15 {$time}", 'UTC')->getTimestamp();
}

function flushMinute(string $time): int
{
    return Telemetry::minute(flushAt($time));
}

/**
 * Records 10:58 to 11:01: rule 1 counts 2 requests and blocks 1 at 10:58, counts 1 at
 * 10:59 and 5 at 11:00; rule 2 sees 3 requests at 10:59, 2 of which it would block,
 * and 1 at 11:01.
 */
function recordFourMinutes(?TelemetryBuffer $buffer = null): void
{
    $buffer ??= Telemetry::buffer();
    $buffer->record(flushMinute('10:58'), 1, [Telemetry::counted('1')]);
    $buffer->record(flushMinute('10:58'), 1, [Telemetry::blocked('1')]);
    $buffer->record(flushMinute('10:59'), 1, [Telemetry::counted('1'), Telemetry::wouldBlock('2')]);
    $buffer->record(flushMinute('10:59'), 1, [Telemetry::wouldBlock('2')]);
    $buffer->record(flushMinute('10:59'), 1, [Telemetry::counted('2')]);
    $buffer->record(flushMinute('11:00'), 5, [Telemetry::counted('1')]);
    $buffer->record(flushMinute('11:01'), 1, [Telemetry::counted('2')]);
}

/**
 * The app's buffer, wrapped so the test sees every call and can make it fail.
 */
function recordingFlushBuffer(): RecordingTelemetryBuffer
{
    $buffer = new RecordingTelemetryBuffer(app(TelemetryBuffer::class));
    app()->instance(TelemetryBuffer::class, $buffer);

    return $buffer;
}

it('AC-10: adds each complete minute to its rule\'s hourly row, the hour in UTC, and leaves the others for later', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();

    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutput('Flushed through 2027-01-15 10:59 UTC.')
        ->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
    ]);
});

it('AC-10: takes in the next minutes on a later run, into the next hour\'s rows', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:03:05')));
    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutput('Flushed through 2027-01-15 11:01 UTC.')
        ->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [1, '2027-01-15 11:00:00', 5, 0, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
        [2, '2027-01-15 11:00:00', 1, 0, 0, null],
    ]);
});

it('AC-10: reads a row\'s hour back in UTC through the model, whatever the app\'s time zone', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    config()->set('app.timezone', 'America/New_York');

    $metric = HourlyMetric::query()->where('rule_id', 1)->firstOrFail();

    expect([$metric->hour->getTimestamp(), $metric->hits, $metric->blocks, $metric->would_blocks, $metric->clients])
        ->toBe([flushAt('10:00'), 3, 1, 0, null]);
});

it('AC-11: changes no row when it runs again, even when the store forgot the last minute flushed', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    $rows = Telemetry::rows();

    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutput('Nothing to flush: the minutes through 2027-01-15 10:59 UTC are flushed.')
        ->assertExitCode(0);
    Cache::store('array')->forget(app(KeyBuilder::class)->telemetryFlushed());
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe($rows);
});

it('AC-11: adds every minute exactly once when a flush fails halfway and runs again', function (): void {
    Exceptions::fake();
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $buffer = recordingFlushBuffer();
    $buffer->countsFailure = StorageUnavailableException::because('The telemetry store is down.');
    $buffer->failingMinutes = [flushMinute('10:59')];

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(1);
    $buffer->countsFailure = null;
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
    ]);
});

it('AC-12: adds each minute once when two flushes run at the same time', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $buffer = recordingFlushBuffer();
    // Another server's flush runs, start to end, while this one reads its first minute.
    $buffer->beforeFirstCounts = fn (): array => app(MetricsFlusher::class)->flush();

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
    ]);
});

it('AC-14: reads at most the last 120 minutes when it has flushed nothing yet', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    Telemetry::buffer()->record(flushMinute('08:51'), 1, [Telemetry::counted('1')]);
    Telemetry::buffer()->record(flushMinute('09:21'), 1, [Telemetry::counted('1')]);
    $buffer = recordingFlushBuffer();

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect([$buffer->countsAsked[0], end($buffer->countsAsked), count($buffer->countsAsked)])->toBe([flushMinute('09:01'), flushMinute('10:59'), 119])
        ->and(Telemetry::rows())->toBe([[1, '2027-01-15 09:00:00', 1, 0, 0, null]]);
});

it('AC-14: starts at the minute after the last one it flushed, and never before the last 120 minutes', function (string $flushed, string $first): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    Telemetry::buffer()->markFlushed(flushMinute($flushed));
    $buffer = recordingFlushBuffer();

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect([$buffer->countsAsked[0], end($buffer->countsAsked)])->toBe([flushMinute($first), flushMinute('10:59')])
        ->and(Telemetry::buffer()->flushedThrough())->toBe(flushMinute('10:59'));
})->with([
    'flushed through 10:50' => ['10:50', '10:51'],
    'flushed through 06:00, before the window' => ['06:00', '09:01'],
]);

it('AC-15: reads the minutes of the rules in the table on the cache driver, and keeps the rows of deleted rules', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    Rule::query()->findOrFail(2)->delete();

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    Rule::query()->findOrFail(1)->delete();

    expect(Telemetry::rows())->toBe([[1, '2027-01-15 10:00:00', 3, 1, 0, null]]);
});

it('AC-16: says how to create the table when it is missing, exits 1, and leaves the buffer as it was', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    Schema::drop(Telemetry::TABLE);

    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutputToContain('The dynamic_throttle_metrics_hourly table does not exist. Publish the package\'s migrations')
        ->assertExitCode(1);

    expect(Telemetry::counts(flushMinute('10:58'), ['1', '2']))->toBe(['1' => [2, 1, 0]])
        ->and(Telemetry::buffer()->flushedThrough())->toBeNull();
});

it('AC-16: reports a store that fails, exits 1, and catches up on the next run', function (): void {
    Exceptions::fake();
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $buffer = recordingFlushBuffer();
    $buffer->countsFailure = StorageUnavailableException::because('The telemetry store is down.');

    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutputToContain('The metrics could not be flushed: The telemetry store is down.')
        ->assertExitCode(1);
    $reported = array_map(fn (Throwable $exception): string => $exception->getMessage(), Exceptions::reported());
    $buffer->countsFailure = null;
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect($reported)->toBe(['The telemetry store is down.'])
        ->and(Telemetry::rows())->toBe([
            [1, '2027-01-15 10:00:00', 3, 1, 0, null],
            [2, '2027-01-15 10:00:00', 3, 0, 2, null],
        ]);
});

it('AC-16: reports a database that fails mid-run, exits 1, and catches up on the next run', function (): void {
    Exceptions::fake();
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $buffer = recordingFlushBuffer();
    // The table goes away after the run started, as a database that fails mid-run.
    $buffer->beforeFirstCounts = fn () => Schema::rename(Telemetry::TABLE, 'metrics_away');

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(1);
    $reports = count(Exceptions::reported());
    Schema::rename('metrics_away', Telemetry::TABLE);
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect($reports)->toBe(1)
        ->and(Telemetry::rows())->toBe([
            [1, '2027-01-15 10:00:00', 3, 1, 0, null],
            [2, '2027-01-15 10:00:00', 3, 0, 2, null],
        ]);
});

it('AC-10: says so when there is nothing to flush yet', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 30));

    $this->artisan('dynamic-throttle:flush-metrics')
        ->expectsOutput('Flushed through 2027-01-15 07:58 UTC.')
        ->assertExitCode(0);

    expect(Telemetry::rows())->toBe([]);
});

it('AC-10: flushes on a database that cannot insert while ignoring errors, such as SQL Server', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $connection = DB::connection();
    $connection->setQueryGrammar(new class($connection) extends SQLiteGrammar
    {
        public function compileInsertOrIgnore(Builder $query, array $values): string
        {
            throw new RuntimeException('This database engine does not support inserting while ignoring errors.');
        }
    });

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
    ]);
});

it('AC-12: adds each minute once when another flush creates the row between this one\'s check and its insert', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $raced = false;
    // Right after this flush finds rule 1's 10:00 row missing, another one creates it with minute 10:58.
    DB::listen(function (QueryExecuted $query) use (&$raced): void {
        // The row check, not hasTable()'s.
        if (! $raced && str_contains($query->sql, 'exists') && str_contains($query->sql, Telemetry::TABLE) && str_contains($query->sql, 'rule_id')) {
            $raced = true;
            DB::table(Telemetry::TABLE)->insert([
                'rule_id' => 1,
                'hour' => '2027-01-15 10:00:00',
                'hits' => 2,
                'blocks' => 1,
                'would_blocks' => 0,
                'clients' => null,
                'flushed_minute' => flushMinute('10:58'),
            ]);
        }
    });

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect([$raced, Telemetry::rows()])->toBe([true, [
        [1, '2027-01-15 10:00:00', 3, 1, 0, null],
        [2, '2027-01-15 10:00:00', 3, 0, 2, null],
    ]]);
});

it('AC-10: reads a row\'s hour back in UTC with the date class the app uses', function (): void {
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:01:30')));
    recordFourMinutes();
    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);
    Date::use(CarbonImmutable::class);

    try {
        $hour = HourlyMetric::query()->where('rule_id', 1)->firstOrFail()->hour;
    } finally {
        Date::useDefault();
    }

    expect([$hour::class, $hour->getTimestamp(), $hour->getTimezone()->getName()])->toBe([CarbonImmutable::class, flushAt('10:00'), 'UTC']);
});

it('SPEC-016 AC-20: moves only the hits, blocks and would-blocks, and gives a rule that was only skipped or ignored no row', function (): void {
    Throttling::rule(['name' => 'Members']);
    // First, as the other tests do: the store expires the keys by the clock.
    $this->travelTo(Carbon::createFromTimestamp(flushAt('11:00:30')));
    $buffer = Telemetry::buffer();
    $buffer->record(flushMinute('10:58'), 2, [Telemetry::guest('1'), Telemetry::skipped('2'), Telemetry::ignored('3')], hash('sha256', 'ip:203.0.113.7'));
    $buffer->record(flushMinute('10:58'), 1, [Telemetry::guest('1', 'B', TelemetryOutcome::Blocked), Telemetry::skipped('2')]);

    $this->artisan('dynamic-throttle:flush-metrics')->assertExitCode(0);

    expect(Telemetry::rows())->toBe([[1, '2027-01-15 10:00:00', 3, 1, 0, null]])
        ->and(Schema::getColumnListing(Telemetry::TABLE))->toBe(['id', 'rule_id', 'hour', 'hits', 'blocks', 'would_blocks', 'clients', 'flushed_minute']);
});
