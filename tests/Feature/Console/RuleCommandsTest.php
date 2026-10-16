<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\Exemptions;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;

/*
| SPEC-013: dynamic-throttle:disable-rule and enable-rule, the recovery path
| ADR-0015 point 6 asks for when the dashboard is unreachable. AC-15 the
| confirmation in production, AC-16 the commands, AC-23 the audit log.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-16: disables a rule, compiles, and requests stop matching it, then enables it again', function (): void {
    $rule = Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $rule->id])
        ->expectsOutputToContain("Rule {$rule->id} (API) is disabled.")
        ->expectsOutputToContain('Servers apply it on their next request.')
        ->assertExitCode(0);
    $disabled = [$rule->refresh()->enabled, array_column(Exemptions::stored()['rules'], 'id'), Throttling::headers($this->get('api/x'))];
    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $rule->id])
        ->expectsOutputToContain("Rule {$rule->id} (API) is enabled.")
        ->assertExitCode(0);

    expect($disabled)->toBe([false, [], []])
        ->and($rule->refresh()->enabled)->toBeTrue()
        ->and(array_column(Exemptions::stored()['rules'], 'id'))->toBe([(string) $rule->id])
        ->and(Throttling::headers($this->get('api/x'))['X-RateLimit-Remaining'] ?? null)->toBe('0');
});

it('AC-16: says there is no such rule, and exits 1', function (string $command, string $id): void {
    Throttling::rule();

    $this->artisan("dynamic-throttle:{$command}", ['id' => $id])
        ->expectsOutputToContain("There is no rule {$id}.")
        ->assertExitCode(1);

    expect(Settings::audit())->toBe([]);
})->with([
    'disable an unknown id' => ['disable-rule', '999'],
    'disable an id that is not a number' => ['disable-rule', 'api'],
    'enable an unknown id' => ['enable-rule', '999'],
]);

it('AC-16: says a rule is already disabled or enabled, writes nothing, and exits 0', function (): void {
    $disabled = Throttling::rule(['enabled' => false]);
    $enabled = Throttling::rule(['name' => 'Web']);

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $disabled->id])
        ->expectsOutputToContain("Rule {$disabled->id} (API) is already disabled.")
        ->assertExitCode(0);
    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $enabled->id])
        ->expectsOutputToContain("Rule {$enabled->id} (Web) is already enabled.")
        ->assertExitCode(0);

    expect(Settings::audit())->toBe([]);
});

it('AC-15: asks in production before disabling an enforcing rule, and writes nothing on no', function (): void {
    $rule = Throttling::rule();
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $rule->id])
        ->expectsConfirmation('Are you sure you want to run this command?', 'no')
        ->assertExitCode(1);

    expect($rule->refresh()->enabled)->toBeTrue();
});

it('AC-15: disables an enforcing rule in production with --force, without asking', function (): void {
    $rule = Throttling::rule();
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $rule->id, '--force' => true])->assertExitCode(0);

    expect($rule->refresh()->enabled)->toBeFalse();
});

it('AC-15: never asks to disable a dry-run rule, nor to enable a rule', function (): void {
    $dryRun = Throttling::rule(['mode' => RuleMode::DryRun]);
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $dryRun->id])->assertExitCode(0);
    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $dryRun->id])->assertExitCode(0);

    expect($dryRun->refresh()->enabled)->toBeTrue();
});

it('AC-23: writes one audit entry for each change, with the CLI\'s actor, and the state before and after', function (): void {
    $rule = Throttling::rule();

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $rule->id])->assertExitCode(0);
    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $rule->id])->assertExitCode(0);

    $entry = fn (string $action, bool $before, bool $after): array => [
        'actor' => Settings::cliActor(),
        'action' => $action,
        'target' => "rule:{$rule->id}",
        'before' => ['enabled' => $before],
        'after' => ['enabled' => $after],
        'ip' => null,
        'user_agent' => null,
    ];

    expect(Settings::audit())->toBe([$entry('rule.disable', true, false), $entry('rule.enable', false, true)])
        ->and(Rule::query()->count())->toBe(1);
});

it('shows a rule\'s name as text, so a name with console styles cannot stop the recovery path (review)', function (): void {
    $rule = Throttling::rule(['name' => '<fg=nope>API</>']);

    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $rule->id])
        ->expectsOutputToContain("Rule {$rule->id} (<fg=nope>API</>) is disabled.")
        ->assertExitCode(0);

    expect($rule->refresh()->enabled)->toBeFalse();
});

it('accepts --force on enable-rule, which never asks, so scripts can pass it to every command', function (): void {
    $rule = Throttling::rule(['enabled' => false]);
    $this->app['env'] = 'production';

    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $rule->id, '--force' => true])->assertExitCode(0);

    expect($rule->refresh()->enabled)->toBeTrue();
});

it('says it cannot read the rule when the database is down, and exits 1', function (string $command): void {
    config()->set('database.connections.unreachable', ['driver' => 'sqlite', 'database' => '/nonexistent/dynamic-throttle.sqlite', 'prefix' => '']);
    config()->set('dynamic-throttle.database.connection', 'unreachable');

    $this->artisan("dynamic-throttle:{$command}", ['id' => '1'])
        ->expectsOutputToContain('Could not read rule 1:')
        ->assertExitCode(1);
})->with(['disable-rule', 'enable-rule']);

it('AC-16: takes the id as a number from Artisan::call(), as from a terminal', function (): void {
    $rule = Throttling::rule(['mode' => RuleMode::DryRun]);

    $disabled = Artisan::call('dynamic-throttle:disable-rule', ['id' => $rule->id]);
    $enabled = [$rule->refresh()->enabled, Artisan::call('dynamic-throttle:enable-rule', ['id' => $rule->id])];

    expect([$disabled, ...$enabled, $rule->refresh()->enabled])->toBe([0, false, 0, true]);
});
