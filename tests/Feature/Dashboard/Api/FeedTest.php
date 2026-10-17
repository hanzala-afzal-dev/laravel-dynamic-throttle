<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryOutcome;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\RecordingTelemetryBuffer;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-030 AC-8: GET api/feed, the live feed for Activity. The clock stands at
| 2027-01-15 12:00:30 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:30', 'UTC'));
    $this->buffer = new RecordingTelemetryBuffer(app(TelemetryBuffer::class));
    app()->instance(TelemetryBuffer::class, $this->buffer);
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-8: answers a viewer the entries, newest first, with their rules\' names, null for a deleted rule', function (): void {
    $login = Throttling::rule(['name' => 'Login', 'pattern' => 'login']);
    $this->buffer->answersFeed = true;
    $this->buffer->feedAnswer = [
        Telemetry::entry((string) $login->id, time: 1_800_000_000_500),
        Telemetry::entry('999', time: 1_800_000_000_000, outcome: TelemetryOutcome::WouldBlock, ip: null, user: 'App\Models\User:42'),
    ];

    $response = DashboardApi::send($this, 'GET', 'feed', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('feed')])->toBe([200, [
        ['time' => 1_800_000_000_500, 'rule' => $login->id, 'outcome' => 'blocked', 'method' => 'POST', 'route' => 'login', 'ip' => '203.0.113.7', 'user' => null, 'identity' => hash('sha256', '203.0.113.7'), 'rule_name' => 'Login'],
        ['time' => 1_800_000_000_000, 'rule' => 999, 'outcome' => 'would_block', 'method' => 'POST', 'route' => 'login', 'ip' => null, 'user' => 'App\Models\User:42', 'identity' => hash('sha256', 'A'), 'rule_name' => null],
    ]])
        // The current minute, and no since.
        ->and($this->buffer->feedAsked)->toBe([[intdiv(Carbon::parse('2027-01-15 12:00:30', 'UTC')->getTimestamp(), 60), null]]);
});

it('AC-8: asks only for the entries after since', function (): void {
    $this->buffer->answersFeed = true;
    $this->buffer->feedAnswer = [];

    DashboardApi::send($this, 'GET', 'feed?since=1800000000123', user: DashboardApi::viewer())->assertOk();

    expect($this->buffer->feedAsked[0][1])->toBe(1_800_000_000_123);
});

it('AC-8: refuses a since that is not a time in milliseconds', function (string $since): void {
    $response = DashboardApi::send($this, 'GET', "feed?since={$since}", user: DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors') ?? [])])->toBe([422, ['since']]);
})->with(['soon', '-1', '1.5']);

it('AC-8: answers a null feed on a store that keeps none, and an empty one with the feed off', function (): void {
    $none = DashboardApi::send($this, 'GET', 'feed', user: DashboardApi::viewer())->json();
    config()->set('dynamic-throttle.telemetry.live_feed', false);
    $off = DashboardApi::send($this, 'GET', 'feed', user: DashboardApi::viewer())->json();

    expect([$none, $off, count($this->buffer->feedAsked)])->toBe([['feed' => null], ['feed' => []], 1]);
});

it('AC-8: answers 503 with the message when the counter store fails', function (): void {
    $this->buffer->feedFailure = StorageUnavailableException::because('The Redis telemetry buffer could not read the feed.');

    $response = DashboardApi::send($this, 'GET', 'feed', user: DashboardApi::viewer());

    expect([$response->status(), $response->json('message')])->toBe([503, 'The Redis telemetry buffer could not read the feed.']);
});
