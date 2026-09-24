<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/*
| SPEC-006 AC-10 and AC-11: compiling from the command line and the scheduler.
*/

beforeEach(function (): void {
    ManifestRepository::flushState();
    RuleSchema::migrate();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * @return list<Event>
 */
function scheduledCompiles(): array
{
    return array_values(array_filter(
        app(Schedule::class)->events(),
        fn (Event $event): bool => str_contains((string) $event->command, 'dynamic-throttle:compile'),
    ));
}

it('AC-10: compiles and prints the version, the rules and the skipped rows', function (): void {
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
    Rule::create(RuleSchema::attributes());

    // The observer compiled version 1,798,761,600,000 when the rule was saved.
    $this->artisan('dynamic-throttle:compile')
        ->expectsOutput('Compiled manifest version 1798761600001: 1 rule, 0 skipped.')
        ->assertExitCode(0);
});

it('AC-10: prints the reason and exits 1 when compiling fails', function (): void {
    Schema::drop('dynamic_throttle_rules');

    $this->artisan('dynamic-throttle:compile')
        ->expectsOutputToContain('Could not compile the manifest:')
        ->assertExitCode(1);
});

it('AC-11: runs the compile every five minutes', function (): void {
    $compiles = scheduledCompiles();

    expect($compiles)->toHaveCount(1)
        ->and($compiles[0]->expression)->toBe('*/5 * * * *');
});

it('AC-11: schedules nothing when manifest.schedule is false', function (): void {
    config()->set('dynamic-throttle.manifest.schedule', false);
    app()->forgetInstance(Schedule::class);

    expect(scheduledCompiles())->toBe([]);
});
