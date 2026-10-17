<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;

/*
| SPEC-010 AC-8 to AC-14: what an allowed, a rejected and an exempt request get
| back. The clock stands at T0, the start of a 60-second window, so a rule's
| remaining is its limit minus the requests it counted.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * The status of each response.
 *
 * @param  list<TestResponse<Symfony\Component\HttpFoundation\Response>>  $responses
 * @return list<int>
 */
function responseStatuses(array $responses): array
{
    return array_map(fn (TestResponse $response): int => $response->status(), $responses);
}

it('AC-8: gives an allowed request the limit and remaining of the most restrictive enforcing rule', function (): void {
    Throttling::rule(['max_attempts' => 10]);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');

    expect(Throttling::headers($this->get('api/x')))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4']);
});

it('AC-8: takes the headers from the first rule in evaluation order among rules with the same remaining', function (int $ordersPriority, int $apiPriority, array $expected): void {
    Throttling::rule(['pattern' => 'api/orders', 'max_attempts' => 4, 'priority' => $ordersPriority]);
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 6, 'priority' => $apiPriority]);
    Throttling::route('api/orders');
    Throttling::route('api/other');

    $this->get('api/other');
    $this->get('api/other');

    // Both rules have 3 requests left: 4 - 1, and 6 - 3.
    expect(Throttling::headers($this->get('api/orders')))->toBe($expected);
})->with([
    'the orders rule first' => [1, 2, ['X-RateLimit-Limit' => '4', 'X-RateLimit-Remaining' => '3']],
    'the api rule first' => [2, 1, ['X-RateLimit-Limit' => '6', 'X-RateLimit-Remaining' => '3']],
]);

it('AC-9: rejects the request over the limit with ThrottleRequestsException, which the app\'s handler renders', function (): void {
    app(ExceptionHandler::class)->renderable(fn (ThrottleRequestsException $exception): ResponseFactory|Response => response('The app renders this 429.', 429, $exception->getHeaders()));
    Throttling::rule(['max_attempts' => 2]);
    Throttling::route('api/x');

    $responses = [$this->get('api/x'), $this->get('api/x'), $this->get('api/x')];

    expect(responseStatuses($responses))->toBe([200, 200, 429])
        ->and($responses[2]->getContent())->toBe('The app renders this 429.')
        ->and(Throttling::headers($responses[2]))->toBe([
            'X-RateLimit-Limit' => '2',
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => '90',
            'X-RateLimit-Reset' => (string) (FakeClock::T0 + 90),
        ]);
});

it('AC-9: renders the 429 as Laravel renders its own, with the rejecting rule\'s headers', function (): void {
    Throttling::rule(['max_attempts' => 2]);
    Throttling::route('api/x');

    $this->getJson('api/x');
    $this->getJson('api/x');
    $response = $this->getJson('api/x');

    expect($response->status())->toBe(429)
        ->and($response->json('message'))->toBe('Too Many Attempts.')
        ->and(Throttling::headers($response))->toBe([
            'X-RateLimit-Limit' => '2',
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => '90',
            'X-RateLimit-Reset' => (string) (FakeClock::T0 + 90),
        ]);
});

it('AC-9: releases the count a lenient rule made for the rejected request (ADR-0021)', function (): void {
    Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 2, 'priority' => 2]);
    Throttling::route('api/login');
    Throttling::route('api/other');

    $login = [$this->get('api/login'), $this->get('api/login'), $this->get('api/login')];

    // The lenient rule counted the two allowed requests, and this one: 10 - 3.
    expect(responseStatuses($login))->toBe([200, 200, 429])
        ->and(Throttling::headers($this->get('api/other')))->toBe(['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '7']);
});

it('AC-10: answers with the rejecting rule\'s response_override, instead of the handler\'s page, with the 429 headers', function (): void {
    app(ExceptionHandler::class)->renderable(fn (ThrottleRequestsException $exception): ResponseFactory|Response => response('The app renders this 429.', 429, $exception->getHeaders()));
    Throttling::rule(['max_attempts' => 1, 'response_override' => ['message' => 'Slow down']]);
    Throttling::route('api/x');

    $responses = [$this->get('api/x'), $this->get('api/x')];

    expect(responseStatuses($responses))->toBe([200, 429])
        ->and($responses[1]->getContent())->toBe('{"message":"Slow down"}')
        ->and($responses[1]->headers->get('Content-Type'))->toBe('application/json')
        ->and(Throttling::headers($responses[1]))->toBe([
            'X-RateLimit-Limit' => '1',
            'X-RateLimit-Remaining' => '0',
            'Retry-After' => '120',
            'X-RateLimit-Reset' => (string) (FakeClock::T0 + 120),
        ]);
});

it('AC-22: answers an empty response_override as the empty JSON object it was saved as', function (): void {
    // {} saved through the array cast reads back as [].
    Throttling::rule(['max_attempts' => 1, 'response_override' => []]);
    Throttling::route('api/x');

    $responses = [$this->get('api/x'), $this->get('api/x')];

    expect(responseStatuses($responses))->toBe([200, 429])
        ->and($responses[1]->getContent())->toBe('{}');
});

it('AC-11: sets no headers where the only matched rule is dry-run, even once it would block', function (): void {
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $responses = [$this->get('api/x'), $this->get('api/x')];

    expect(responseStatuses($responses))->toBe([200, 200])
        ->and(array_map(Throttling::headers(...), $responses))->toBe([[], []]);
});

it('AC-11: sets no headers for a client exempt from every matched rule', function (): void {
    Throttling::rule();
    Throttling::rule(['max_attempts' => 5]);
    Exemption::forIp('203.0.113.0/24')->save();
    Throttling::route('api/x');

    $exempt = $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])->get('api/x');
    $other = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7'])->get('api/x');

    expect(Throttling::headers($exempt))->toBe([])
        ->and(Throttling::headers($other))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4']);
});

it('AC-11: sets no headers on a path no rule matches', function (): void {
    Throttling::rule();
    Throttling::route('other/x');
    Throttling::route('api/x');

    expect(Throttling::headers($this->get('other/x')))->toBe([])
        ->and(Throttling::headers($this->get('api/x')))->toBe(['X-RateLimit-Limit' => '60', 'X-RateLimit-Remaining' => '59']);
});

it('AC-12: never rejects with a dry-run rule, which still counts: promoted, it rejects the next request', function (): void {
    $rule = Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    $dryRun = [$this->get('api/x'), $this->get('api/x'), $this->get('api/x')];
    $rule->update(['mode' => RuleMode::Enforce]);

    expect(responseStatuses($dryRun))->toBe([200, 200, 200])
        ->and($this->get('api/x')->status())->toBe(429);
});

it('AC-12: never takes the headers from a dry-run rule, even a more restrictive one', function (): void {
    Throttling::rule(['max_attempts' => 10]);
    Throttling::rule(['max_attempts' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');

    expect(Throttling::headers($this->get('api/x')))->toBe(['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '9']);
});

it('AC-12: releases a dry-run rule matched before the rule that rejects, like an enforcing twin (ADR-0021)', function (): void {
    $dryRun = Throttling::rule(['pattern' => 'api/*', 'max_attempts' => 10, 'priority' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::rule(['pattern' => 'api/login', 'max_attempts' => 2, 'priority' => 2]);
    Throttling::route('api/login');
    Throttling::route('api/other');

    $login = [$this->get('api/login'), $this->get('api/login'), $this->get('api/login')];
    $dryRun->update(['mode' => RuleMode::Enforce]);

    // Promoted, it counted what the AC-9 lenient rule counts: the two allowed requests, and this one.
    expect(responseStatuses($login))->toBe([200, 200, 429])
        ->and(Throttling::headers($this->get('api/other')))->toBe(['X-RateLimit-Limit' => '10', 'X-RateLimit-Remaining' => '7']);
});

it('AC-13: limits HEAD with a GET rule', function (): void {
    Throttling::rule(['method' => RequestMethod::Get, 'max_attempts' => 1]);
    Throttling::route('api/x');

    expect([$this->call('HEAD', 'api/x')->status(), $this->call('HEAD', 'api/x')->status()])->toBe([200, 429]);
});

it('AC-13: leaves a GET alone under a POST rule', function (): void {
    Throttling::rule(['method' => RequestMethod::Post, 'max_attempts' => 1]);
    Route::middleware('dynamic.throttle')->match(['GET', 'POST'], 'api/x', fn (): string => 'ok');

    $gets = [$this->get('api/x'), $this->get('api/x')];
    $posts = [$this->post('api/x'), $this->post('api/x')];

    expect(responseStatuses($gets))->toBe([200, 200])
        ->and(array_map(Throttling::headers(...), $gets))->toBe([[], []])
        ->and(responseStatuses($posts))->toBe([200, 429]);
});

it('AC-14: counts two users apart under a user rule', function (): void {
    Throttling::rule(['scope' => Scope::User, 'max_attempts' => 1]);
    Throttling::route('api/x');

    $statuses = [
        $this->actingAs(new TestUser(['id' => 1]))->get('api/x')->status(),
        $this->actingAs(new TestUser(['id' => 2]))->get('api/x')->status(),
        $this->actingAs(new TestUser(['id' => 1]))->get('api/x')->status(),
    ];

    expect($statuses)->toBe([200, 200, 429]);
});

it('AC-14: never limits a client exempt by IP', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Exemption::forIp('203.0.113.0/24')->save();
    Throttling::route('api/x');

    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.7']);
    $exempt = [$this->get('api/x'), $this->get('api/x'), $this->get('api/x')];
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.7']);
    $other = [$this->get('api/x'), $this->get('api/x')];

    expect(responseStatuses($exempt))->toBe([200, 200, 200])
        ->and(responseStatuses($other))->toBe([200, 429]);
});
