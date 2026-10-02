<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\CacheLimiterStore;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Enums\MissingIdentity;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\CountingCacheStore;
use Afzal\DynamicThrottle\Tests\Support\Explanations;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/*
| SPEC-014: dynamic-throttle:explain runs the middleware's decision on the
| request it describes, without counting it. SQLite and the array cache
| store; the clock stands at T0+10, 2027-01-15 08:00:10 UTC, so the window
| of 60 s started 10 s ago. Requests made through the middleware come from
| 127.0.0.1, the command's default IP.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The command's text output, which must succeed.
 *
 * @param  array<string, mixed>  $options
 */
function explainText(string $method, string $path, array $options = []): string
{
    $exitCode = Artisan::call('dynamic-throttle:explain', ['method' => $method, 'path' => $path, ...$options]);
    $output = Artisan::output();

    expect($exitCode)->toBe(0, $output);

    return $output;
}

/**
 * A custom scope whose resolver always fails, as one that calls a service that is down.
 */
function brokenScope(): void
{
    DynamicThrottle::scope('broken', fn (): never => throw new RuntimeException('The plans service is down.'));
}

/*
| AC-1 and AC-2: the rules, and who the client counts as.
*/

it('AC-1: lists the matched rules in evaluation order, with each one\'s mode, limit, window and failure policy', function (): void {
    Throttling::rule(['name' => 'API', 'pattern' => 'api/x']);
    Throttling::rule(['name' => 'Audit', 'pattern' => '*', 'max_attempts' => 1000, 'window_seconds' => 3600, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Burst', 'max_attempts' => 5, 'window_seconds' => 10, 'priority' => 10, 'on_failure' => FailurePolicy::Closed]);
    Throttling::rule(['name' => 'Admin', 'pattern' => 'admin/*']);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x');

    expect(array_map(fn (array $rule): array => [$rule['id'], $rule['name'], $rule['pattern'], $rule['priority'], $rule['mode'], $rule['limit'], $rule['window_seconds'], $rule['on_failure']], $explanation['rules']))->toBe([
        ['3', 'Burst', 'api/*', 10, 'enforce', 5, 10, 'closed'],
        ['1', 'API', 'api/x', 100, 'enforce', 60, 60, 'open'],
        ['2', 'Audit', '*', 100, 'dry_run', 1000, 3600, 'open'],
    ]);
});

it('AC-1: shows the rules as text, in evaluation order', function (): void {
    Throttling::rule(['name' => 'API', 'pattern' => 'api/x']);
    Throttling::rule(['name' => 'Audit', 'pattern' => '*', 'max_attempts' => 1000, 'window_seconds' => 3600, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Burst', 'max_attempts' => 5, 'window_seconds' => 10, 'priority' => 10, 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');

    $output = explainText('GET', '/api/x');
    $positions = array_map(fn (string $line): int|false => strpos($output, $line), ['Rule 3, Burst: counts the request', 'Rule 1, API: counts the request', 'Rule 2, Audit: counts the request']);
    $inOrder = $positions;
    sort($inOrder);

    expect($output)->toContain('Request: GET api/x from 127.0.0.1')
        ->toContain('Limit: 5 per 10 s, enforce, on failure closed')
        ->toContain('Limit: 60 per 60 s, enforce, on failure open')
        ->toContain('Limit: 1000 per 3600 s, dry run, on failure open')
        ->and($positions)->not->toContain(false)
        ->and($positions)->toBe($inOrder);
});

it('AC-2: shows the identity as given, and the hash the middleware counts under', function (string $scope, ?string $parameter, array $options, array $headers, bool $asUser, string $identity): void {
    Explanations::users(42);
    DynamicThrottle::scope('plan', fn (Request $request): ?string => $request->header('X-Plan'));
    Throttling::rule(['scope' => Scope::from($scope), 'scope_parameter' => $parameter]);
    Throttling::route('api/x');

    $rule = Explanations::rules(Explanations::json('GET', 'api/x', ['--ip' => '203.0.113.7', ...$options]))['1'];

    // The request the middleware counts, with no guard left holding the command's user.
    Auth::forgetGuards();

    if ($asUser) {
        $this->actingAs(User::query()->findOrFail(42));
    }

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('api/x', $headers)->assertOk();

    expect([$rule['status'], $rule['scope'], $rule['scope_parameter'], $rule['identity']])->toBe(['counts', $scope, $parameter, $identity])
        ->and($rule['hash'])->toBe(Identities::hashed($identity))
        ->and(Explanations::counterHashes('1'))->toBe([$rule['hash']]);
})->with([
    'ip' => ['ip', null, [], [], false, 'ip:203.0.113.7'],
    'user' => ['user', null, ['--user' => '42'], [], true, 'user:'.User::class.':42'],
    'api_token' => ['api_token', null, ['--header' => ['Authorization: Bearer abc']], ['Authorization' => 'Bearer abc'], false, 'token:abc'],
    'header' => ['header', 'X-Api-Key', ['--header' => ['X-Api-Key: abc']], ['X-Api-Key' => 'abc'], false, 'header:abc'],
    'global' => ['global', null, [], [], false, 'global'],
    'custom' => ['custom', 'plan', ['--header' => ['X-Plan: gold']], ['X-Plan' => 'gold'], false, 'custom:gold'],
]);

it('AC-2: shows the identity and its hash as text', function (): void {
    Throttling::rule();
    Throttling::route('api/x');

    expect(explainText('GET', 'api/x', ['--ip' => '203.0.113.7']))
        ->toContain('Identity: ip:203.0.113.7')
        ->toContain('Hash: '.Identities::hashed('ip:203.0.113.7'));
});

it('AC-2: looks the user up with the provider of --guard, and authenticates it on that guard, which becomes the default, as auth leaves it', function (): void {
    Explanations::users(42);
    // Another provider than the default guard's, whose users are another class.
    config()->set('auth.providers.partners', ['driver' => 'database', 'table' => 'users']);
    config()->set('auth.guards.partner', ['driver' => 'session', 'provider' => 'partners']);
    Throttling::rule(['scope' => Scope::User]);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x', ['--user' => '42', '--guard' => 'partner']);

    expect($explanation['request'])->toMatchArray(['user' => '42', 'guard' => 'partner'])
        ->and($explanation['rules'][0]['identity'])->toBe('user:'.GenericUser::class.':42');
});

it('AC-2: shows the user and the guard as text', function (): void {
    Explanations::users(42);
    Throttling::rule(['scope' => Scope::User]);
    Throttling::route('api/x');

    expect(explainText('GET', 'api/x', ['--user' => '42']))
        ->toContain('Request: GET api/x from 127.0.0.1, as user 42 of guard web')
        ->toContain('Identity: user:'.User::class.':42');
});

it('AC-2: counts a client without the user as a guest when no --user is given', function (): void {
    Throttling::rule(['scope' => Scope::User]);
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x', ['--ip' => '203.0.113.7'])['rules'][0]['identity'])->toBe('guest:203.0.113.7');
});

/*
| AC-3: the rules that do not count the request.
*/

it('AC-3: shows an exempt rule, a rule without an identity, a rule whose resolver throws, and a rule beyond the cap', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    brokenScope();
    $exempt = Throttling::rule(['name' => 'Exempt', 'priority' => 10]);
    Throttling::rule(['name' => 'Partners', 'priority' => 20, 'scope' => Scope::Header, 'scope_parameter' => 'X-Partner', 'on_missing_identity' => MissingIdentity::Skip]);
    Throttling::rule(['name' => 'Plans', 'priority' => 30, 'scope' => Scope::Custom, 'scope_parameter' => 'broken']);
    Throttling::rule(['name' => 'Counted', 'priority' => 40]);
    Throttling::rule(['name' => 'Capped', 'priority' => 50]);
    Exemption::forIp('127.0.0.1')->forRule($exempt)->save();
    Throttling::route('api/x');

    $rules = Explanations::rules(Explanations::json('GET', 'api/x'));

    expect(array_map(fn (array $rule): array => [$rule['status'], $rule['exemption'], $rule['error'], $rule['identity'] !== null, $rule['counter'] !== null], $rules))->toBe([
        '1' => ['exempt', '1', null, false, false],
        '2' => ['skipped', null, null, false, false],
        '3' => ['failed', null, 'The identity for custom:broken could not be resolved: The plans service is down.', false, false],
        '4' => ['counts', null, null, true, true],
        '5' => ['ignored', null, null, false, false],
    ]);
});

it('AC-3: says why each rule does not count the request, as text', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 1);
    brokenScope();
    $exempt = Throttling::rule(['name' => 'Exempt', 'priority' => 10]);
    Throttling::rule(['name' => 'Partners', 'priority' => 20, 'scope' => Scope::Header, 'scope_parameter' => 'X-Partner', 'on_missing_identity' => MissingIdentity::Skip]);
    Throttling::rule(['name' => 'Plans', 'priority' => 30, 'scope' => Scope::Custom, 'scope_parameter' => 'broken']);
    Throttling::rule(['name' => 'Counted', 'priority' => 40]);
    Throttling::rule(['name' => 'Capped', 'priority' => 50]);
    Exemption::forIp('127.0.0.1')->forRule($exempt)->save();
    Throttling::route('api/x');

    expect(explainText('GET', 'api/x'))
        ->toContain('Rule 1, Exempt: exempt, by exemption 1')
        ->toContain('Rule 2, Partners: skipped: the request has no identity for it')
        ->toContain('Rule 3, Plans: failed: The identity for custom:broken could not be resolved: The plans service is down.')
        ->toContain('Rule 4, Counted: counts the request')
        ->toContain('Rule 5, Capped: ignored: over the cap of 1 enforcing rule per request');
});

it('AC-3: shows the rules after a fail-closed rule that stopped evaluation as not reached', function (): void {
    brokenScope();
    Throttling::rule(['name' => 'Plans', 'priority' => 10, 'scope' => Scope::Custom, 'scope_parameter' => 'broken', 'on_failure' => FailurePolicy::Closed]);
    Throttling::rule(['name' => 'After', 'priority' => 20]);
    Throttling::route('api/x');

    $rules = Explanations::rules(Explanations::json('GET', 'api/x'));

    expect([$rules['1']['status'], $rules['2']['status'], $rules['2']['identity'], $rules['2']['counter']])->toBe(['failed', 'not_reached', null, null])
        ->and(explainText('GET', 'api/x'))->toContain('Rule 2, After: not reached: rule 1 stopped evaluation');
});

/*
| AC-4: the requests the middleware lets through untouched.
*/

it('AC-4: says the kill switch lets the request through untouched', function (): void {
    config()->set('dynamic-throttle.enabled', false);
    Throttling::rule();
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x')['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'disabled', 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'api/x'))->toContain('Decision: untouched: dynamic-throttle.enabled is off');
});

it('AC-4: says the package\'s own routes go through untouched', function (): void {
    Throttling::rule(['pattern' => '*']);
    Throttling::route('dynamic-throttle')->name('dynamic-throttle.dashboard');

    expect(Explanations::json('GET', 'dynamic-throttle')['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'own_route', 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'dynamic-throttle'))->toContain('Decision: untouched: the route is one of the package\'s own');
});

it('AC-4: says an except path goes through untouched, and which pattern lists it', function (): void {
    Throttling::rule(['pattern' => '*']);
    Throttling::route('horizon/api/stats');

    expect(Explanations::json('GET', 'horizon/api/stats')['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'except', 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'horizon/api/stats'))->toContain('Decision: untouched: except lists horizon/*');
});

it('AC-4: says bypass lets the request through untouched, and until when', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    Settings::bypass(until: FakeClock::T0 + 10 + 1800);

    $explanation = Explanations::json('GET', 'api/x');

    expect($explanation['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'bypass', 'rule' => null, 'headers' => []])
        ->and($explanation['settings'])->toBe(['bypass' => ['until' => '2027-01-15T08:30:10Z'], 'scale' => null])
        ->and(explainText('GET', 'api/x'))->toContain('Decision: untouched: bypass is on until 2027-01-15 08:30:10 UTC');
});

it('AC-4: says a request no rule matches goes through untouched', function (): void {
    Throttling::rule(['pattern' => 'admin/*']);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x');

    expect($explanation['rules'])->toBe([])
        ->and($explanation['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'no_rule', 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'api/x'))->toContain('Decision: untouched: no rule matches');
});

/*
| AC-5: the scale factor.
*/

it('AC-5: shows each rule\'s limit under the scale factor, and the factor with its end time', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::rule(['name' => 'Audit', 'max_attempts' => 3, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    Settings::scale('2', until: FakeClock::T0 + 10 + 1800);

    $explanation = Explanations::json('GET', 'api/x');

    expect($explanation['settings'])->toBe(['bypass' => null, 'scale' => ['factor' => '2', 'until' => '2027-01-15T08:30:10Z']])
        ->and(array_map(fn (array $rule): array => [$rule['max_attempts'], $rule['limit']], $explanation['rules']))->toBe([[5, 10], [3, 6]])
        ->and($explanation['decision']['headers'])->toBe(['X-RateLimit-Limit' => 10, 'X-RateLimit-Remaining' => 9])
        ->and(explainText('GET', 'api/x'))
        ->toContain('Scale: factor 2 until 2027-01-15 08:30:10 UTC')
        ->toContain('Limit: 10 per 60 s (5 × 2), enforce, on failure open');
});

it('AC-5: shows a factor with no end time', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    Settings::scale('0.5');

    expect(Explanations::json('GET', 'api/x')['settings'])->toBe(['bypass' => null, 'scale' => ['factor' => '0.5', 'until' => null]])
        ->and(explainText('GET', 'api/x'))->toContain('Scale: factor 0.5, with no end time');
});

/*
| AC-6 and AC-7: the counters, and the decision they lead to.
*/

it('AC-6: shows the count, what remains and the next request\'s outcome, and the headers the request that follows gets', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');

    foreach (range(1, 3) as $request) {
        $this->get('api/x')->assertOk();
    }

    $afterThree = Explanations::json('GET', 'api/x');
    $fourth = $this->get('api/x');
    $this->get('api/x')->assertOk();
    $afterFive = Explanations::json('GET', 'api/x');
    $sixth = $this->get('api/x');

    expect($afterThree['rules'][0]['counter'])->toBe(['count' => 3, 'remaining' => 2, 'next' => 'allowed', 'retry_after' => null])
        ->and($afterThree['decision'])->toBe(['outcome' => 'allowed', 'reason' => null, 'rule' => null, 'headers' => ['X-RateLimit-Limit' => 5, 'X-RateLimit-Remaining' => 1]])
        ->and([$fourth->status(), Throttling::headers($fourth)])->toBe([200, array_map(strval(...), $afterThree['decision']['headers'])])
        ->and($afterFive['rules'][0]['counter'])->toBe(['count' => 5, 'remaining' => 0, 'next' => 'limited', 'retry_after' => 62])
        ->and($afterFive['decision'])->toBe(['outcome' => 'rejected', 'reason' => null, 'rule' => '1', 'headers' => [
            'X-RateLimit-Limit' => 5,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => 62,
            'X-RateLimit-Reset' => FakeClock::T0 + 72,
        ]])
        ->and([$sixth->status(), Throttling::headers($sixth)])->toBe([429, array_map(strval(...), $afterFive['decision']['headers'])]);
});

it('AC-6: shows the counter as text', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');

    foreach (range(1, 3) as $request) {
        $this->get('api/x')->assertOk();
    }

    $afterThree = explainText('GET', 'api/x');
    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();

    expect($afterThree)->toContain('Counter: 3 counted, 2 remaining: the next request is allowed')
        ->toContain('Decision: allowed, with X-RateLimit-Limit 5 and X-RateLimit-Remaining 1')
        ->and(explainText('GET', 'api/x'))
        ->toContain('Counter: 5 counted, 0 remaining: the next request is limited, Retry-After 62 s')
        ->toContain('Decision: rejected by rule 1: 429, Retry-After 62 s');
});

it('AC-6: shows the plan without counters on a custom LimiterStore, and no decision while an enforcing rule counts', function (): void {
    $store = FailingStore::failingReleases(Identities::store());
    $this->app->bind(LimiterStore::class, fn (): LimiterStore => $store);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x');

    expect([$explanation['rules'][0]['status'], $explanation['rules'][0]['counter']])->toBe(['counts', null])
        ->and($explanation['counters'])->toBe(['read' => false, 'error' => FailingStore::class.' is a custom counter store: explain reads the counters of the package\'s cache and Redis drivers only.'])
        ->and($explanation['decision'])->toBe(['outcome' => 'unknown', 'reason' => null, 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'api/x'))
        ->toContain('Counters: '.FailingStore::class.' is a custom counter store')
        ->toContain('Decision: unknown: the counters cannot be read');
});

it('AC-6: still decides on a custom LimiterStore when only dry-run rules count', function (): void {
    $store = FailingStore::failingReleases(Identities::store());
    $this->app->bind(LimiterStore::class, fn (): LimiterStore => $store);
    Throttling::rule(['max_attempts' => 5, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x')['decision'])->toBe(['outcome' => 'allowed', 'reason' => null, 'rule' => null, 'headers' => []]);
});

it('AC-7: gives the 429 of the first enforcing rule that would reject the next request, as the request that follows gets it', function (): void {
    Throttling::rule(['name' => 'Wide', 'priority' => 10, 'max_attempts' => 10]);
    Throttling::rule(['name' => 'Audit', 'priority' => 20, 'max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['name' => 'Tight', 'priority' => 30, 'max_attempts' => 1]);
    Throttling::rule(['name' => 'Tighter', 'priority' => 40, 'max_attempts' => 1]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();

    $explanation = Explanations::json('GET', 'api/x');
    $next = $this->get('api/x');

    expect(array_map(fn (array $rule): string => $rule['counter']['next'], $explanation['rules']))->toBe(['allowed', 'limited', 'limited', 'limited'])
        ->and($explanation['decision'])->toBe(['outcome' => 'rejected', 'reason' => null, 'rule' => '3', 'headers' => [
            'X-RateLimit-Limit' => 1,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => 110,
            'X-RateLimit-Reset' => FakeClock::T0 + 120,
        ]])
        ->and([$next->status(), Throttling::headers($next)])->toBe([429, array_map(strval(...), $explanation['decision']['headers'])]);
});

it('AC-7: gives the 503 of a fail-closed rule that cannot be checked, as the request that follows gets it', function (): void {
    brokenScope();
    Throttling::rule(['scope' => Scope::Custom, 'scope_parameter' => 'broken', 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x');
    $next = $this->get('api/x');

    expect($explanation['decision'])->toBe(['outcome' => 'unavailable', 'reason' => null, 'rule' => '1', 'headers' => ['Retry-After' => 10]])
        ->and([$next->status(), $next->headers->get('Retry-After')])->toBe([503, '10'])
        ->and(explainText('GET', 'api/x'))->toContain('Decision: unavailable by rule 1: 503, Retry-After 10 s');
});

it('AC-7: gives the decision of a counter store that fails: the first fail-closed rule answers 503', function (): void {
    $cache = new CountingCacheStore;
    $cache->failing = true;
    $this->app->bind(LimiterStore::class, fn (): LimiterStore => new CacheLimiterStore(new Repository($cache), app(KeyBuilder::class), app(Clock::class)));
    Throttling::rule(['name' => 'Open', 'priority' => 10]);
    Throttling::rule(['name' => 'Closed', 'priority' => 20, 'on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');

    $explanation = Explanations::json('GET', 'api/x');

    expect(array_map(fn (array $rule): array => [$rule['status'], $rule['counter']], $explanation['rules']))->toBe([['counts', null], ['counts', null]])
        ->and($explanation['counters']['read'])->toBeFalse()
        ->and($explanation['counters']['error'])->toContain(CountingCacheStore::DOWN)
        ->and($explanation['decision'])->toBe(['outcome' => 'unavailable', 'reason' => null, 'rule' => '2', 'headers' => ['Retry-After' => 10]])
        ->and($this->get('api/x')->status())->toBe(503);
});

it('AC-7: gives the decision of a counter store that fails: without a fail-closed rule, the request goes through', function (): void {
    $cache = new CountingCacheStore;
    $cache->failing = true;
    $this->app->bind(LimiterStore::class, fn (): LimiterStore => new CacheLimiterStore(new Repository($cache), app(KeyBuilder::class), app(Clock::class)));
    Throttling::rule();
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x')['decision'])->toBe(['outcome' => 'failed', 'reason' => null, 'rule' => '1', 'headers' => []])
        ->and($this->get('api/x')->status())->toBe(200);
});

/*
| AC-8: the route, and whether it runs the middleware after authentication.
*/

it('AC-8: lists the route\'s middleware in running order, with dynamic.throttle after auth', function (): void {
    Throttling::rule();
    // The group lists dynamic.throttle first: the priority list runs auth before it.
    Route::middleware('dynamic.throttle')->group(function (): void {
        Route::middleware('auth:header')->get('api/x', fn (): string => 'ok')->name('orders');
    });

    $explanation = Explanations::json('GET', 'api/x');

    expect($explanation['route'])->toBe([
        'uri' => 'api/x',
        'name' => 'orders',
        'middleware' => ['Illuminate\Auth\Middleware\Authenticate:header', DynamicThrottleMiddleware::class],
        'throttled' => true,
        'after_auth' => true,
    ])
        ->and(explainText('GET', 'api/x'))->toContain('dynamic.throttle runs after authentication');
});

it('AC-8: says when no authentication runs before dynamic.throttle on the route', function (): void {
    Throttling::rule(['scope' => Scope::User]);
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x')['route'])->toMatchArray(['throttled' => true, 'after_auth' => false])
        ->and(explainText('GET', 'api/x'))->toContain('No authentication middleware runs before dynamic.throttle: user rules count this route\'s clients as guests');
});

it('AC-8: says no rule applies on a route that does not run dynamic.throttle, and on a path no route matches', function (): void {
    Throttling::rule(['pattern' => '*']);
    Route::get('plain', fn (): string => 'ok');

    $plain = Explanations::json('GET', 'plain');
    $nowhere = Explanations::json('GET', 'nowhere');

    expect($plain['route'])->toMatchArray(['uri' => 'plain', 'middleware' => [], 'throttled' => false, 'after_auth' => null])
        ->and($plain['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'not_attached', 'rule' => null, 'headers' => []])
        ->and($nowhere['route'])->toBeNull()
        ->and($nowhere['decision'])->toBe(['outcome' => 'untouched', 'reason' => 'not_attached', 'rule' => null, 'headers' => []])
        ->and(explainText('GET', 'plain'))->toContain('Decision: untouched: the route does not run dynamic.throttle')
        ->and(explainText('GET', 'nowhere'))->toContain('Decision: untouched: no route matches, and dynamic.throttle is not global middleware');
});

it('AC-8: applies the rules to a path no route matches when the app runs dynamic.throttle as global middleware', function (): void {
    app(Kernel::class)->pushMiddleware(DynamicThrottleMiddleware::class);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 5]);

    $explanation = Explanations::json('GET', 'nowhere');

    expect($explanation['route'])->toBeNull()
        ->and($explanation['decision'])->toBe(['outcome' => 'allowed', 'reason' => null, 'rule' => null, 'headers' => ['X-RateLimit-Limit' => 5, 'X-RateLimit-Remaining' => 4]]);
});

/*
| AC-9 and AC-10: read-only, and JSON.
*/

it('AC-9: changes no counter, writes nothing to the database, and leaves the cache as it was', function (): void {
    Explanations::users(42);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::rule(['name' => 'Users', 'scope' => Scope::User, 'max_attempts' => 5]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();
    $cache = Explanations::arrayCache();
    $writes = [];
    DB::listen(function (QueryExecuted $query) use (&$writes): void {
        if (preg_match('/\A\s*(insert|update|delete|replace|create|drop|alter)\b/i', $query->sql) === 1) {
            $writes[] = $query->sql;
        }
    });

    Explanations::json('GET', 'api/x', ['--user' => '42']);
    explainText('GET', 'api/x');
    app()->terminate();

    expect(Explanations::arrayCache())->toBe($cache)
        ->and($writes)->toBe([]);
});

it('AC-9: rebuilds nothing when the manifest is missing', function (): void {
    Throttling::rule();
    Cache::forget(app(KeyBuilder::class)->manifest());

    Artisan::call('dynamic-throttle:explain', ['method' => 'GET', 'path' => 'api/x']);
    app()->terminate();

    expect(Cache::get(app(KeyBuilder::class)->manifest()))->toBeNull();
});

it('AC-10: prints one JSON object with every part of the explanation, and nothing else', function (): void {
    Throttling::rule();
    Throttling::route('api/x');

    $exitCode = Artisan::call('dynamic-throttle:explain', ['method' => 'GET', 'path' => 'api/x', '--json' => true]);
    $output = trim(Artisan::output());
    $explanation = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

    expect($exitCode)->toBe(0)
        ->and($output)->toStartWith('{')->toEndWith('}')
        ->and(array_keys($explanation))->toBe(['request', 'manifest', 'settings', 'route', 'rules', 'counters', 'decision'])
        ->and($explanation['request'])->toBe(['method' => 'GET', 'path' => 'api/x', 'ip' => '127.0.0.1', 'user' => null, 'guard' => null, 'headers' => []])
        ->and(array_keys($explanation['manifest']))->toBe(['version', 'rules', 'exemptions'])
        ->and($explanation['manifest']['rules'])->toBe(1)
        ->and($explanation['settings'])->toBe(['bypass' => null, 'scale' => null])
        ->and(array_keys($explanation['rules'][0]))->toBe([
            'id', 'name', 'method', 'pattern', 'priority', 'mode', 'limit', 'max_attempts', 'window_seconds', 'on_failure',
            'scope', 'scope_parameter', 'status', 'identity', 'hash', 'exemption', 'error', 'counter',
        ])
        ->and($explanation['rules'][0]['method'])->toBe('*')
        ->and($explanation['counters'])->toBe(['read' => true, 'error' => null]);
});

it('AC-10: names the headers given, without their values', function (): void {
    Throttling::rule();
    Throttling::route('api/x');

    expect(Explanations::json('GET', 'api/x', ['--header' => ['X-Api-Key: secret', 'Authorization: Bearer abc']])['request']['headers'])
        ->toBe(['X-Api-Key', 'Authorization']);
});

it('AC-10: prints an error as one JSON object too, and nothing else', function (): void {
    $exitCode = Artisan::call('dynamic-throttle:explain', ['method' => 'FETCH', 'path' => 'api/x', '--json' => true]);

    expect($exitCode)->toBe(1)
        ->and(json_decode(trim(Artisan::output()), true, 512, JSON_THROW_ON_ERROR))
        ->toBe(['error' => 'The method must be GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS, got "FETCH".']);
});

/*
| AC-11 and AC-12: inputs and the manifest.
*/

it('AC-11: accepts a method in any case, and ignores a leading slash and a query string', function (): void {
    Throttling::rule();
    Throttling::route('api/x');

    $explanation = Explanations::json('get', '/api/x?page=2');

    expect([$explanation['request']['method'], $explanation['request']['path'], count($explanation['rules'])])->toBe(['GET', 'api/x', 1]);
});

it('AC-11: prints why an input is wrong, and exits 1', function (array $input, string $message): void {
    Explanations::users(42);
    Throttling::rule();
    Throttling::route('api/x');

    $this->artisan('dynamic-throttle:explain', ['method' => 'GET', 'path' => 'api/x', ...$input])
        ->expectsOutputToContain($message)
        ->assertExitCode(1);
})->with([
    'an unknown method' => [['method' => 'FETCH'], 'The method must be GET, HEAD, POST, PUT, PATCH, DELETE or OPTIONS, got "FETCH".'],
    'a user that does not exist' => [['--user' => '43'], 'There is no user 43 for the guard web.'],
    'a guard that does not exist' => [['--user' => '42', '--guard' => 'nope'], 'The guard "nope" cannot be used: Auth guard [nope] is not defined.'],
    'a guard without a user' => [['--guard' => 'web'], '--guard needs --user: it names the guard that looks the user up.'],
    'an IP that is not one' => [['--ip' => '203.0.113.300'], 'The IP must be an IPv4 or IPv6 address, got "203.0.113.300".'],
    'a header without a colon' => [['--header' => ['X-Api-Key abc']], 'A header must be a name, a colon and a value, such as "X-Api-Key: abc", got "X-Api-Key abc".'],
]);

it('AC-12: says the manifest is missing, and exits 1', function (): void {
    Throttling::rule();
    Throttling::route('api/x');
    Cache::forget(app(KeyBuilder::class)->manifest());

    $this->artisan('dynamic-throttle:explain', ['method' => 'GET', 'path' => 'api/x'])
        ->expectsOutputToContain('The manifest is missing: run php artisan dynamic-throttle:compile.')
        ->assertExitCode(1);
});

it('AC-12: says the manifest cannot be read, and exits 1', function (): void {
    Throttling::route('api/x');
    Cache::forever(app(KeyBuilder::class)->manifest(), '{"format": 3');

    $this->artisan('dynamic-throttle:explain', ['method' => 'GET', 'path' => 'api/x'])
        ->expectsOutputToContain('The manifest cannot be read: The manifest is not JSON')
        ->assertExitCode(1);
});
