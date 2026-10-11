<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\RateLimitExceeded;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-029 AC-3: the runbook's "A bad rule blocks /api"
| (docs/runbook.md#a-bad-rule-blocks-api). The test follows the section's
| steps, and checks what the section says you will see. The bad rule counts
| every client of /api/* in one global counter, 1 request a minute, so it
| answers 429 to every request after the first. The app runs in production,
| so the commands that loosen limits would ask first. The clock starts at T0.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    $this->app['env'] = 'production';
    $this->rule = Throttling::rule(['name' => 'API', 'pattern' => 'api/*', 'scope' => Scope::Global, 'max_attempts' => 1]);
    Throttling::route('api/orders');
    Throttling::route('api/users');
    $this->get('api/orders');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * A request from a client of its own.
 *
 * @return TestResponse<Response>
 */
function fromClient(string $uri, string $ip): TestResponse
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip])->get($uri);
}

it('AC-3: answers 429 to every client of /api, and dispatches RateLimitExceeded for each, naming the rule', function (): void {
    Event::fake([RateLimitExceeded::class]);

    $statuses = [fromClient('api/orders', '198.51.100.1')->status(), fromClient('api/users', '198.51.100.2')->status()];
    $events = Event::dispatched(RateLimitExceeded::class)->map(fn (array $arguments): array => [$arguments[0]->ruleId, $arguments[0]->ruleName, $arguments[0]->path]);

    expect($statuses)->toBe([429, 429])
        ->and($events->values()->all())->toBe([[(string) $this->rule->id, 'API', 'api/orders'], [(string) $this->rule->id, 'API', 'api/users']]);
});

it('AC-3: names the rule and its 429 in dynamic-throttle:explain', function (): void {
    Artisan::call('dynamic-throttle:explain', ['method' => 'GET', 'path' => '/api/orders']);

    expect(Artisan::output())
        ->toContain("Rule {$this->rule->id}, API: counts the request")
        ->toContain('Scope: global')
        ->toContain("Decision: rejected by rule {$this->rule->id}: 429");
});

it('AC-3: shows the rule\'s id in dynamic-throttle:list, and stops the 429s with disable-rule --force, which writes one audit entry', function (): void {
    $this->artisan('dynamic-throttle:list')
        ->expectsTable(
            ['ID', 'Name', 'Method', 'Pattern', 'Scope', 'Limit', 'Mode', 'On failure', 'Enabled'],
            [[(string) $this->rule->id, 'API', '*', 'api/*', 'global', '1 per 60s', 'enforce', 'open', 'yes']],
        )
        ->assertExitCode(0);
    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $this->rule->id, '--force' => true])->assertExitCode(0);

    $after = [fromClient('api/orders', '198.51.100.1'), fromClient('api/orders', '198.51.100.1')];

    expect(array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $after))->toBe([[200, []], [200, []]])
        ->and(array_column(Settings::audit(), 'action'))->toBe(['rule.disable']);
});

it('AC-3: applies the rule fixed with the model once enable-rule brings it back', function (): void {
    $this->artisan('dynamic-throttle:disable-rule', ['id' => (string) $this->rule->id, '--force' => true])->assertExitCode(0);

    $this->rule->update(['scope' => Scope::Ip, 'max_attempts' => 60]);
    $this->artisan('dynamic-throttle:enable-rule', ['id' => (string) $this->rule->id])->assertExitCode(0);
    $first = fromClient('api/orders', '198.51.100.1');
    $other = fromClient('api/orders', '198.51.100.2');

    expect([Throttling::headers($first), Throttling::headers($other)])->toBe([
        ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59'],
        ['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59'],
    ]);
});

it('AC-3: lets every request through with bypass --force when the rule cannot be found quickly, and status shows its end time', function (): void {
    $this->artisan('dynamic-throttle:bypass', ['state' => 'on', '--for' => '30m', '--force' => true])->assertExitCode(0);
    $during = [fromClient('api/orders', '198.51.100.1'), fromClient('api/users', '198.51.100.2')];

    $this->artisan('dynamic-throttle:status')
        ->expectsOutputToContain('on until 2027-01-15 08:30:00 UTC, 30 minutes left')
        ->assertExitCode(0);
    expect(array_map(fn (TestResponse $response): array => [$response->status(), Throttling::headers($response)], $during))->toBe([[200, []], [200, []]]);
});
