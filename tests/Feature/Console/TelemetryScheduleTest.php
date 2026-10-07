<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/*
| SPEC-015 AC-18: the scheduler flushes the metrics every minute, without
| overlapping, and prunes them daily.
*/

/**
 * The scheduled runs of a command, after the scheduler is built again with the config.
 *
 * @return list<Event>
 */
function scheduledRuns(string $command): array
{
    app()->forgetInstance(Schedule::class);

    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, $command),
    ));
}

it('AC-18: flushes the metrics every minute, without overlapping', function (): void {
    $flushes = scheduledRuns('dynamic-throttle:flush-metrics');

    expect($flushes)->toHaveCount(1)
        ->and([$flushes[0]->expression, $flushes[0]->withoutOverlapping])->toBe(['* * * * *', true]);
});

it('AC-18: prunes the metrics daily, keeping telemetry.retention_hours', function (?int $retention, string $hours): void {
    if ($retention !== null) {
        config()->set('dynamic-throttle.telemetry.retention_hours', $retention);
    }

    $prunes = scheduledRuns('dynamic-throttle:prune');

    expect($prunes)->toHaveCount(1)
        ->and($prunes[0]->expression)->toBe('0 0 * * *')
        ->and((string) $prunes[0]->command)->toEndWith("dynamic-throttle:prune --hours={$hours}");
})->with([
    'the default, 30 days' => [null, '720'],
    'two days' => [48, '48'],
]);

it('AC-18: schedules neither with telemetry.schedule or telemetry.enabled false', function (string $key): void {
    config()->set($key, false);

    expect([scheduledRuns('dynamic-throttle:flush-metrics'), scheduledRuns('dynamic-throttle:prune')])->toBe([[], []]);
})->with([
    'telemetry.schedule' => ['dynamic-throttle.telemetry.schedule'],
    'telemetry.enabled' => ['dynamic-throttle.telemetry.enabled'],
]);

it('AC-18: prunes with at most 1,000,000 hours, whatever telemetry.retention_hours asks', function (): void {
    config()->set('dynamic-throttle.telemetry.retention_hours', 10 ** 12);

    $prunes = scheduledRuns('dynamic-throttle:prune');

    expect((string) $prunes[0]->command)->toEndWith('dynamic-throttle:prune --hours=1000000');
});
