<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\RedisTelemetryBuffer;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Afzal\DynamicThrottle\Tests\Support\Telemetry;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Route as Router;

/*
| SPEC-030 AC-1 and AC-7 on Redis: a blocked request's entry reaches the counter
| store in the request's telemetry call, and the feed reads it back.
*/

beforeEach(function (): void {
    Throttling::prepare();
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-1: keeps a blocked request\'s entry, with its route\'s template and whole IP, and the feed reads it back', function (string $client): void {
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        $this->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.feed', ['host' => (string) env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 0]);
    config()->set('dynamic-throttle.driver', 'redis');
    config()->set('dynamic-throttle.redis_connection', 'feed');
    config()->set('dynamic-throttle.prefix', 'dt-feed-'.bin2hex(random_bytes(4)).':');
    $now = Carbon::parse('2027-01-15 12:00:30', 'UTC');
    $this->travelTo($now);
    $rule = Throttling::rule(['max_attempts' => 1]);
    Router::middleware('dynamic.throttle')->post('api/orders/{order}', fn (): string => 'ok');

    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->post('api/orders/12')->assertOk();
    $this->withServerVariables(['REMOTE_ADDR' => '2001:db8::1'])->post('api/orders/12')->assertStatus(429);
    $feed = Telemetry::buffer()->feed(Telemetry::minute($now->getTimestamp()));

    expect(Telemetry::buffer())->toBeInstanceOf(RedisTelemetryBuffer::class)
        ->and(array_map(fn (FeedEntry $entry): array => $entry->toArray(), $feed ?? []))->toBe([[
            'time' => $now->getTimestamp() * 1_000,
            'rule' => (string) $rule->id,
            'outcome' => 'blocked',
            'method' => 'POST',
            'route' => 'api/orders/{order}',
            'ip' => '2001:db8::1',
            'user' => null,
            'identity' => Identities::hashed('ip:2001:db8::/64'),
        ]]);
})->with(['phpredis', 'predis']);
