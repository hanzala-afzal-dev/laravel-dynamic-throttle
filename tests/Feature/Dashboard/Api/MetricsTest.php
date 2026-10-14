<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Telemetry\MinuteCounts;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\HourlyMetric;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery\MockInterface;

/*
| SPEC-017 AC-5 to AC-8: each rule's numbers per minute for the last hour, from
| the counter store, or per hour for the last day or week, from the hourly
| table; a rule's offenders; and the health report. The clock stands at
| 2027-01-15 12:00:30 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:30', 'UTC'));
    $this->minute = intdiv(Settings::at('2027-01-15 12:00:30'), 60);
    $this->hour = intdiv(Settings::at('2027-01-15 12:00:30'), 3_600);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return list<int>
 */
function zeros(int $count): array
{
    return array_fill(0, $count, 0);
}

/**
 * A row of the hourly table, as the flush writes it.
 */
function hourlyRow(int $ruleId, string $hour, int $hits, int $blocks = 0, int $wouldBlocks = 0): void
{
    DB::table((new HourlyMetric)->getTable())->insert([
        'rule_id' => $ruleId, 'hour' => $hour, 'hits' => $hits, 'blocks' => $blocks, 'would_blocks' => $wouldBlocks, 'clients' => null, 'flushed_minute' => 0,
    ]);
}

/*
| AC-5: the last hour, per minute.
*/

it('AC-5: gives the last 60 minutes, oldest first, for each rule with numbers, 0 where it had none', function (): void {
    $api = Throttling::rule(['name' => 'API']);
    Throttling::rule(['name' => 'Quiet', 'pattern' => 'quiet']);
    $id = (string) $api->id;
    Telemetry::buffer()->record($this->minute, 1, [Telemetry::blocked($id)]);
    Telemetry::buffer()->record($this->minute, 1, [Telemetry::counted($id)]);
    Telemetry::buffer()->record($this->minute - 59, 1, [Telemetry::wouldBlock($id)]);
    Telemetry::buffer()->record($this->minute - 60, 1, [Telemetry::counted($id)]);

    $response = DashboardApi::send($this, 'GET', 'metrics', user: DashboardApi::viewer());
    $times = $response->json('times');

    expect($response->status())->toBe(200)
        ->and([$response->json('period'), $response->json('step_seconds'), count($times), $times[0], $times[59]])
        ->toBe(['hour', 60, 60, '2027-01-15T11:01:00+00:00', '2027-01-15T12:00:00+00:00'])
        ->and($response->json('rules'))->toBe([[
            'id' => $api->id,
            'name' => 'API',
            'hits' => [1, ...zeros(58), 2],
            'blocks' => [...zeros(59), 1],
            'would_blocks' => [1, ...zeros(59)],
        ]]);
});

it('AC-5: shows a rule deleted since by its id, with name null', function (): void {
    $this->mock(TelemetryBuffer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('counts')->andReturnUsing(fn (int $minute): array => $minute === $this->minute ? ['77' => new MinuteCounts(3, 1, 0)] : []);
    });

    $response = DashboardApi::send($this, 'GET', 'metrics', user: DashboardApi::viewer());

    expect($response->json('rules'))->toBe([['id' => 77, 'name' => null, 'hits' => [...zeros(59), 3], 'blocks' => [...zeros(59), 1], 'would_blocks' => zeros(60)]]);
});

it('AC-5: answers 503 with the store\'s error when the counter store is down', function (): void {
    $this->mock(TelemetryBuffer::class, function (MockInterface $mock): void {
        $mock->shouldReceive('counts')->andThrow(StorageUnavailableException::because('Connection refused.'));
    });

    $response = DashboardApi::send($this, 'GET', 'metrics', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('message')])->toBe([503, 'Connection refused.']);
});

/*
| AC-6: the last day or week, per hour.
*/

it('AC-6: gives the last 24 hours per hour from the hourly table, the current hour included', function (): void {
    $api = Throttling::rule(['name' => 'API']);
    hourlyRow($api->id, '2027-01-15 12:00:00', 10, 2);
    hourlyRow($api->id, '2027-01-14 13:00:00', 5);
    hourlyRow($api->id, '2027-01-14 12:00:00', 99);
    hourlyRow(77, '2027-01-15 11:00:00', 4, 0, 4);

    $response = DashboardApi::send($this, 'GET', 'metrics?period=day', user: DashboardApi::viewer());
    $times = $response->json('times');

    expect($response->status())->toBe(200)
        ->and([$response->json('period'), $response->json('step_seconds'), count($times), $times[0], $times[23]])
        ->toBe(['day', 3_600, 24, '2027-01-14T13:00:00+00:00', '2027-01-15T12:00:00+00:00'])
        ->and($response->json('rules'))->toBe([
            ['id' => $api->id, 'name' => 'API', 'hits' => [5, ...zeros(22), 10], 'blocks' => [...zeros(23), 2], 'would_blocks' => zeros(24)],
            ['id' => 77, 'name' => null, 'hits' => [...zeros(22), 4, 0], 'blocks' => zeros(24), 'would_blocks' => [...zeros(22), 4, 0]],
        ]);
});

it('AC-6: gives the last 168 hours for a week', function (): void {
    $api = Throttling::rule(['name' => 'API']);
    hourlyRow($api->id, '2027-01-08 13:00:00', 7);

    $response = DashboardApi::send($this, 'GET', 'metrics?period=week', user: DashboardApi::viewer());

    expect([count($response->json('times')), $response->json('times.0'), $response->json('rules.0.hits')])
        ->toBe([168, '2027-01-08T13:00:00+00:00', [7, ...zeros(167)]]);
});

it('AC-6: refuses another period with a 422', function (): void {
    $response = DashboardApi::send($this, 'GET', 'metrics?period=month', user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors'))])->toBe([422, ['period']]);
});

it('AC-6: answers 503 with the way out when the hourly table is missing', function (): void {
    Schema::connection((new HourlyMetric)->getConnectionName())->drop((new HourlyMetric)->getTable());

    $response = DashboardApi::send($this, 'GET', 'metrics?period=day', user: DashboardApi::viewer());

    expect($response->status())->toBe(503)
        ->and($response->json('message'))->toStartWith('The dynamic_throttle_metrics_hourly table does not exist.');
});

/*
| AC-7: a rule's offenders.
*/

it('AC-7: gives the current hour\'s top offenders by identity hash, most first, and how many clients', function (): void {
    $rule = Throttling::rule();
    $this->mock(TelemetryBuffer::class, function (MockInterface $mock) use ($rule): void {
        $mock->shouldReceive('offenders')->with((string) $rule->id, $this->hour, 100)->andReturn(['hash-a' => 12, 'hash-b' => 3]);
        $mock->shouldReceive('clients')->with((string) $rule->id, $this->hour)->andReturn(2);
    });

    $response = DashboardApi::send($this, 'GET', "rules/{$rule->id}/offenders", user: DashboardApi::viewer());

    expect($response->status())->toBe(200)
        ->and($response->json())->toBe([
            'hour' => '2027-01-15T12:00:00+00:00',
            'offenders' => [['hash' => 'hash-a', 'count' => 12], ['hash' => 'hash-b', 'count' => 3]],
            'clients' => 2,
        ]);
});

it('AC-7: gives the previous hour\'s with ?hour=previous', function (): void {
    $rule = Throttling::rule();
    $this->mock(TelemetryBuffer::class, function (MockInterface $mock) use ($rule): void {
        $mock->shouldReceive('offenders')->with((string) $rule->id, $this->hour - 1, 100)->andReturn([]);
        $mock->shouldReceive('clients')->with((string) $rule->id, $this->hour - 1)->andReturn(0);
    });

    $response = DashboardApi::send($this, 'GET', "rules/{$rule->id}/offenders?hour=previous", user: DashboardApi::viewer());

    expect($response->json())->toBe(['hour' => '2027-01-15T11:00:00+00:00', 'offenders' => [], 'clients' => 0]);
});

it('AC-7: gives null for both on the cache driver, which keeps neither', function (): void {
    $rule = Throttling::rule();

    $response = DashboardApi::send($this, 'GET', "rules/{$rule->id}/offenders", user: DashboardApi::viewer());

    expect($response->json())->toBe(['hour' => '2027-01-15T12:00:00+00:00', 'offenders' => null, 'clients' => null]);
});

it('AC-7: refuses another hour with a 422, and an unknown rule with a 404', function (): void {
    $rule = Throttling::rule();

    expect(DashboardApi::send($this, 'GET', "rules/{$rule->id}/offenders?hour=tomorrow", user: DashboardApi::viewer())->status())->toBe(422)
        ->and(DashboardApi::send($this, 'GET', 'rules/999/offenders', user: DashboardApi::viewer())->status())->toBe(404);
});

/*
| AC-8: health.
*/

it('AC-8: answers the report dynamic-throttle:health --json prints', function (): void {
    Artisan::call('dynamic-throttle:health', ['--json' => true]);
    $printed = json_decode(Artisan::output(), true);

    $response = DashboardApi::send($this, 'GET', 'health', user: DashboardApi::viewer());

    expect([$response->status(), $response->json()])->toBe([200, $printed]);
});

it('AC-8: answers 200 with the status inside when a check fails', function (): void {
    config()->set('dynamic-throttle.store', 'missing');

    $response = DashboardApi::send($this, 'GET', 'health', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('status')])->toBe([200, 'error']);
});
