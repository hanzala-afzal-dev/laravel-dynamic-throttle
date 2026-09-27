<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-011 AC-1 to AC-8: the RateLimitWouldBlock event a dry-run rule
| dispatches when it would have rejected a request (ADR-0012), after the
| response (decision 2). The clock stands at T0, the start of a 60-second
| window, and the event is faked, as an app's own tests fake it.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
    Event::fake([RateLimitWouldBlock::class]);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The RateLimitWouldBlock events dispatched so far, in order.
 *
 * @return list<RateLimitWouldBlock>
 */
function wouldBlockEvents(): array
{
    return Event::dispatched(RateLimitWouldBlock::class)->map(fn (array $arguments): RateLimitWouldBlock => $arguments[0])->values()->all();
}

/**
 * The names of the rules the RateLimitWouldBlock events so far came from, in order.
 *
 * @return list<string>
 */
function wouldBlockRuleNames(): array
{
    return array_map(fn (RateLimitWouldBlock $event): string => $event->ruleName, wouldBlockEvents());
}

it('AC-1: dispatches one event for the request a dry-run rule would block, with the rule, the client\'s hash, the wait and the request', function (): void {
    $rule = Throttling::rule(['name' => 'API trial', 'max_attempts' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');
    $beforeTheThird = wouldBlockEvents();
    $third = $this->get('api/x');
    $events = wouldBlockEvents();

    expect($beforeTheThird)->toBe([])
        ->and($third->status())->toBe(200)
        ->and($events)->toHaveCount(1)
        ->and(get_object_vars($events[0]))->toBe([
            'ruleId' => (string) $rule->getKey(),
            'ruleName' => 'API trial',
            'limit' => 2,
            'windowSeconds' => 60,
            'scope' => Scope::Ip,
            // The key the rule counts this client under, never the address itself (ADR-0024).
            'identityHash' => app(IdentityHasher::class)->hash('ip:127.0.0.1'),
            'retryAfter' => 90,
            'method' => 'GET',
            'path' => 'api/x',
        ])
        // A queued listener gets the event back from serialize() (decision 3).
        ->and(unserialize(serialize($events[0])))->toEqual($events[0]);
});

it('AC-2: dispatches the event when the kernel terminates the request, after the response is built', function (): void {
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    $this->get('api/x');
    $kernel = app(HttpKernel::class);
    $request = Request::create('/api/x');

    $response = $kernel->handle($request);
    $afterHandle = wouldBlockEvents();
    $kernel->terminate($request, $response);

    expect($response->getStatusCode())->toBe(200)
        ->and($afterHandle)->toBe([])
        ->and(wouldBlockEvents())->toHaveCount(1);
});

it('AC-3: dispatches the event on exactly the requests an enforcing twin rejects, and the twins keep equal counts', function (): void {
    Throttling::rule(['name' => 'Enforced', 'pattern' => 'enforced/*', 'max_attempts' => 3]);
    $dryRun = Throttling::rule(['name' => 'Dry run', 'pattern' => 'dry-run/*', 'max_attempts' => 3, 'mode' => RuleMode::DryRun]);
    Throttling::route('enforced/x');
    Throttling::route('dry-run/x');
    $rejected = [];
    $wouldBlock = [];

    // 20 requests over three 60-second windows, some bunched and some spread out.
    foreach ([0, 5, 10, 15, 20, 30, 59, 60, 70, 80, 90, 100, 110, 119, 120, 125, 150, 160, 170, 179] as $seconds) {
        $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + $seconds));
        $rejected[] = $this->get('enforced/x')->status() === 429;
        $eventsBefore = count(wouldBlockEvents());
        $this->get('dry-run/x');
        $wouldBlock[] = count(wouldBlockEvents()) > $eventsBefore;
    }

    // Promoted, the dry-run rule answers the next request as its twin does, because its counts are the twin's.
    $dryRun->update(['mode' => RuleMode::Enforce]);
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 180));
    $twin = $this->get('enforced/x');
    $promoted = $this->get('dry-run/x');

    expect($wouldBlock)->toBe($rejected)
        // Rejections in each of the three windows, so the comparison means something.
        ->and(array_keys(array_filter($rejected)))->toBe([3, 4, 5, 6, 7, 8, 10, 12, 13, 15, 17, 18, 19])
        ->and([$promoted->status(), Throttling::headers($promoted)])->toBe([$twin->status(), Throttling::headers($twin)]);
});

it('AC-4: dispatches one event for each dry-run rule that would block the request, in evaluation order', function (): void {
    // Saved first, so evaluation order comes from the priorities, not from the ids.
    Throttling::rule(['name' => 'Second', 'max_attempts' => 1, 'priority' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'First', 'max_attempts' => 1, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');

    expect(wouldBlockRuleNames())->toBe(['First', 'Second']);
});

it('AC-5: dispatches no event for a client exempt from the rule', function (): void {
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Exemption::forIp('203.0.113.0/24')->save();
    Throttling::route('api/x');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    $this->get('api/x');
    $this->get('api/x');
    $this->get('api/x');
    $exempt = wouldBlockEvents();
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
    $this->get('api/x');
    $this->get('api/x');

    expect($exempt)->toBe([])
        ->and(wouldBlockEvents())->toHaveCount(1);
});

it('AC-5: dispatches no event for a rule that skips a request without its identity', function (): void {
    Throttling::rule(['scope' => Scope::User, 'on_missing_identity' => MissingIdentity::Skip, 'max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');
    $this->get('api/x');
    $guests = wouldBlockEvents();
    $this->actingAs(new TestUser(['id' => 1]));
    $this->get('api/x');
    $this->get('api/x');

    expect($guests)->toBe([])
        ->and(wouldBlockEvents())->toHaveCount(1);
});

it('AC-5: dispatches no event for a dry-run rule over its mode\'s cap (ADR-0025)', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    Throttling::rule(['name' => 'Counted', 'max_attempts' => 1, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Over the cap', 'max_attempts' => 1, 'priority' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');
    $this->get('api/x');

    expect(wouldBlockRuleNames())->toBe(['Counted', 'Counted']);
});

it('AC-5: dispatches no event for a dry-run rule after the rule that rejected the request, and one for a dry-run rule before it', function (): void {
    Throttling::rule(['name' => 'Before', 'pattern' => 'api/*', 'max_attempts' => 1, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Enforced', 'pattern' => 'api/login', 'max_attempts' => 1, 'priority' => 2]);
    Throttling::rule(['name' => 'After', 'pattern' => 'api/*', 'max_attempts' => 1, 'priority' => 3, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/login');
    Throttling::route('api/other');

    $login = [$this->get('api/login')->status(), $this->get('api/login')->status()];
    $atLogin = wouldBlockRuleNames();
    $this->get('api/other');

    expect($login)->toBe([200, 429])
        ->and($atLogin)->toBe(['Before'])
        // After was at its limit since the first request, and blocks only here: the
        // second request's evaluation stopped at the rule that rejected it.
        ->and(wouldBlockRuleNames())->toBe(['Before', 'Before', 'After']);
});

it('AC-5: dispatches no event with enabled off', function (): void {
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    config()->set('dynamic-throttle.enabled', false);
    $this->get('api/x');
    $this->get('api/x');
    $this->get('api/x');
    $whileOff = wouldBlockEvents();
    config()->set('dynamic-throttle.enabled', true);
    $this->get('api/x');
    $this->get('api/x');

    expect($whileOff)->toBe([])
        ->and(wouldBlockEvents())->toHaveCount(1);
});

it('AC-5: dispatches no event on a path in except', function (): void {
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('up');
    Throttling::route('api/x');

    $this->get('up');
    $this->get('up');
    $this->get('up');
    $excepted = wouldBlockEvents();
    $this->get('api/x');
    $this->get('api/x');

    expect($excepted)->toBe([])
        ->and(wouldBlockEvents())->toHaveCount(1);
});

it('AC-6: releases nothing when a dry-run rule would block: the enforcing rule before it keeps its counts (ADR-0021)', function (): void {
    Throttling::rule(['name' => 'Enforced', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['name' => 'Dry run', 'max_attempts' => 2, 'priority' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $this->get('api/x');
    $this->get('api/x');
    $this->get('api/x');
    $fourth = $this->get('api/x');

    // The dry-run rule would block the third and fourth requests, and the enforcing rule still counted them: 10 - 4.
    expect(wouldBlockRuleNames())->toBe(['Dry run', 'Dry run'])
        ->and(Throttling::headers($fourth))->toBe(['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '6']);
});

it('AC-7: reports a listener that throws, once, keeps the response, and still dispatches the next event', function (): void {
    // Dispatched for real here, so the listener below runs.
    Event::except(RateLimitWouldBlock::class);
    $heard = [];
    Event::listen(RateLimitWouldBlock::class, function (RateLimitWouldBlock $event) use (&$heard): void {
        $heard[] = $event->ruleName;

        if ($event->ruleName === 'First') {
            throw new RuntimeException('The listener failed.');
        }
    });
    Throttling::rule(['name' => 'First', 'max_attempts' => 1, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Second', 'max_attempts' => 1, 'priority' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    Exceptions::fake();

    $this->get('api/x');
    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and($response->getContent())->toBe('ok')
        ->and($heard)->toBe(['First', 'Second'])
        ->and(array_map(fn (Throwable $exception): array => [$exception::class, $exception->getMessage()], Exceptions::reported()))
        ->toBe([[RuntimeException::class, 'The listener failed.']]);
});

it('AC-8: dispatches the event when the evaluation failed, and reports the failure', function (): void {
    DynamicThrottle::scope('tenant', fn (): string => throw new RuntimeException('The tenant lookup failed.'));
    Throttling::rule(['name' => 'Dry run', 'max_attempts' => 1, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Tenant', 'scope' => Scope::Custom, 'scope_parameter' => 'tenant', 'max_attempts' => 5, 'priority' => 2]);
    Throttling::route('api/x');
    Exceptions::fake();

    $first = $this->get('api/x');
    $afterTheFirst = wouldBlockRuleNames();
    $second = $this->get('api/x');

    // Each request fails on the tenant rule, and reports it (SPEC-010 decision 2).
    expect([$first->status(), $second->status()])->toBe([200, 200])
        ->and($afterTheFirst)->toBe([])
        ->and(wouldBlockRuleNames())->toBe(['Dry run'])
        ->and(array_map(fn (Throwable $exception): string => $exception->getMessage(), Exceptions::reported()))->toBe([
            'The identity for custom:tenant could not be resolved: The tenant lookup failed.',
            'The identity for custom:tenant could not be resolved: The tenant lookup failed.',
        ]);
});
