<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\RuleMode;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| SPEC-013 AC-5 to AC-11: the runtime settings on the request path (ADR-0011).
| Bypass lets every request through untouched. The scale factor multiplies
| each matched rule's limit. Both end at their end time, checked with each
| request's clock, and come only from the manifest, never the database.
| Throttling::prepare() makes every request read the manifest from the cache,
| as on PHP-FPM, unless a test keeps it in memory.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-5: lets every request through untouched while bypass is on, then counts from the first again', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $bypass = Settings::bypass();

    $bypassed = array_map(function (): array {
        $response = $this->get('api/x');

        return [$response->status(), Throttling::headers($response)];
    }, range(1, 3));
    $bypass->delete();
    $first = $this->get('api/x');
    $second = $this->get('api/x');

    expect($bypassed)->toBe([[200, []], [200, []], [200, []]])
        ->and([$first->status(), Throttling::headers($first)['X-RateLimit-Remaining'] ?? null])->toBe([200, '0'])
        ->and($second->status())->toBe(429);
});

it('AC-5: sends no event while bypass is on', function (): void {
    Event::fake([RateLimitWouldBlock::class]);
    Throttling::rule(['max_attempts' => 1, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    Settings::bypass();

    foreach (range(1, 3) as $request) {
        $this->get('api/x');
    }

    Event::assertNotDispatched(RateLimitWouldBlock::class);
});

it('AC-6: counts each matched rule against its limit times the factor', function (): void {
    Throttling::rule(['max_attempts' => 10]);
    Throttling::route('api/x');
    Settings::scale('2');

    $responses = array_map(fn () => $this->get('api/x'), range(1, 21));

    expect(array_map(fn ($response): int => $response->status(), array_slice($responses, 19)))->toBe([200, 429])
        ->and(Throttling::headers($responses[0])['X-RateLimit-Limit'] ?? null)->toBe('20');
});

it('AC-6: rounds the scaled limit up, never below 1, and exactly', function (int $limit, string $factor, int $allowed): void {
    Throttling::rule(['max_attempts' => $limit]);
    Throttling::route('api/x');
    Settings::scale($factor);

    $response = $this->get('api/x');

    expect(Throttling::headers($response)['X-RateLimit-Limit'] ?? null)->toBe((string) $allowed);
})->with([
    'limit 3 by 0.5' => [3, '0.5', 2],
    'limit 1 by 0.1' => [1, '0.1', 1],
    'limit 100 by 1.1' => [100, '1.1', 110],
]);

it('AC-7: keeps the counts made before the factor changed', function (): void {
    Throttling::rule(['max_attempts' => 10]);
    Throttling::route('api/x');

    foreach (range(1, 5) as $request) {
        $this->get('api/x');
    }

    Settings::scale('2');
    $sixth = $this->get('api/x');

    expect(Throttling::headers($sixth))->toMatchArray(['X-RateLimit-Limit' => '20', 'X-RateLimit-Remaining' => '14']);
});

it('AC-8: scales a dry-run rule too, so it would block at the scaled limit', function (): void {
    Event::fake([RateLimitWouldBlock::class]);
    Throttling::rule(['max_attempts' => 2, 'mode' => RuleMode::DryRun]);
    Throttling::route('api/x');
    Settings::scale('2');

    $dispatched = [];

    foreach (range(1, 5) as $request) {
        $this->get('api/x');
        $dispatched[] = count(Event::dispatched(RateLimitWouldBlock::class));
    }

    expect($dispatched)->toBe([0, 0, 0, 0, 1]);
    Event::assertDispatched(RateLimitWouldBlock::class, fn (RateLimitWouldBlock $event): bool => $event->limit === 4);
});

it('AC-9: ends bypass at its end time, with no compile in between', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    Settings::bypass(until: FakeClock::T0 + 60);

    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 59));
    $before = $this->get('api/x');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $at = $this->get('api/x');

    expect(Throttling::headers($before))->toBe([])
        ->and(Throttling::headers($at)['X-RateLimit-Remaining'] ?? null)->toBe('0');
});

it('AC-9: ends the factor at its end time, with no compile in between', function (): void {
    Throttling::rule(['max_attempts' => 10]);
    Throttling::route('api/x');
    Settings::scale('2', until: FakeClock::T0 + 60);

    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 59));
    $before = $this->get('api/x');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $at = $this->get('api/x');

    expect([Throttling::headers($before)['X-RateLimit-Limit'] ?? null, Throttling::headers($at)['X-RateLimit-Limit'] ?? null])
        ->toBe(['20', '10']);
});

it('AC-10: applies the settings of a manifest kept in the process, and ends them there', function (): void {
    config()->set('dynamic-throttle.manifest.refresh_seconds', 3_600);
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    Settings::bypass(until: FakeClock::T0 + 60);

    // The first request keeps the manifest in memory for the hour.
    $bypassed = $this->get('api/x');
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 60));
    $counted = $this->get('api/x');

    expect(Throttling::headers($bypassed))->toBe([])
        ->and(Throttling::headers($counted)['X-RateLimit-Remaining'] ?? null)->toBe('0');
});

it('AC-11: runs no database query for a request with bypass on, or with the factor', function (): void {
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $bypass = Settings::bypass();
    $queries = [];
    DB::listen(function ($query) use (&$queries): void {
        $queries[] = $query->sql;
    });

    $bypassed = $this->get('api/x');
    $whileBypassed = $queries;
    $bypass->delete();
    Settings::scale('2');
    $queries = [];
    $scaled = $this->get('api/x');

    expect([$bypassed->status(), Throttling::headers($bypassed)])->toBe([200, []])
        ->and(Throttling::headers($scaled)['X-RateLimit-Limit'] ?? null)->toBe('10')
        ->and([$whileBypassed, $queries])->toBe([[], []]);
});
