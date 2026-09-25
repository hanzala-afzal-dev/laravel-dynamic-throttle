<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\RuleSchema;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Exceptions;

/*
| SPEC-010 AC-17: DynamicThrottle::fake(), for the app's own tests (ADR-0020
| point 12). Each test starts as an app's test does: no tables until it
| migrates, and the manifest kept in memory for refresh_seconds, as by default.
*/

beforeEach(function (): void {
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
    ManifestRepository::flushState();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-17: limits through in-memory counters, with no Redis to reach, and starts every test from zero', function (): void {
    RuleSchema::migrate();
    // Nothing listens on port 1: without the fake, every attempt would fail, and let the request through.
    config()->set('database.redis.default', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    config()->set('dynamic-throttle.driver', 'redis');
    DynamicThrottle::fake();
    Throttling::rule(['max_attempts' => 2]);
    Throttling::route('api/x');

    expect([$this->get('api/x')->status(), $this->get('api/x')->status(), $this->get('api/x')->status()])->toBe([200, 200, 429]);
})->with([
    'a first test' => ['first'],
    'a second test, which shares no count with the first' => ['second'],
]);

it('AC-17: applies the rules saved before fake() from the first request', function (): void {
    RuleSchema::migrate();
    Throttling::rule(['max_attempts' => 1]);
    DynamicThrottle::fake();
    Throttling::route('api/x');

    expect([$this->get('api/x')->status(), $this->get('api/x')->status()])->toBe([200, 429]);
});

it('AC-17: forgets the manifest the process kept, so a test never sees the rules of the test before it', function (): void {
    RuleSchema::migrate();
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    // The process now keeps the manifest in memory, with the rule.
    $this->get('api/x');
    // As a new test would start: the rule is gone from the table.
    Rule::query()->delete();

    DynamicThrottle::fake();
    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([]);
});

it('AC-17: works without the package\'s tables: no rule applies, and nothing is reported', function (): void {
    Exceptions::fake();
    DynamicThrottle::fake();
    Throttling::route('api/x');

    $response = $this->get('api/x');

    expect($response->status())->toBe(200)
        ->and(Throttling::headers($response))->toBe([])
        ->and(Exceptions::reported())->toBe([]);
});
