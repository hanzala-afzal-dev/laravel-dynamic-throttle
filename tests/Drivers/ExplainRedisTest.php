<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stores\RedisLimiterStore;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Tests\Support\Explanations;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-014 AC-6 and AC-9 on Redis: dynamic-throttle:explain reads the Redis
| driver's counters, gives the numbers of the request that follows, and
| changes nothing. The driver takes Laravel's frozen clock instead of Redis
| TIME, so the windows are those of the cache driver's tests: T0+10.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 10));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

/**
 * Counts on Redis through the given client, under a prefix of this test's own, so
 * runs under --parallel never share counters.
 */
function explainOnRedis(string $client): void
{
    if ($client === 'phpredis' && ! extension_loaded('redis')) {
        test()->markTestSkipped('ext-redis is not installed.');
    }

    config()->set('database.redis.client', $client);
    config()->set('database.redis.explain', ['host' => (string) env('REDIS_HOST', '127.0.0.1'), 'port' => (int) env('REDIS_PORT', 6379), 'database' => 0]);
    config()->set('dynamic-throttle.prefix', 'dt-explain-'.bin2hex(random_bytes(4)).':');
    $store = new RedisLimiterStore(app('redis'), 'explain', app(KeyBuilder::class), new LaravelClock);
    app()->bind(LimiterStore::class, fn (): LimiterStore => $store);
}

it('AC-6: shows the Redis driver\'s counters, and the headers the request that follows gets', function (string $client): void {
    explainOnRedis($client);
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

    expect($afterThree['counters'])->toBe(['read' => true, 'error' => null])
        ->and($afterThree['rules'][0]['counter'])->toBe(['count' => 3, 'remaining' => 2, 'next' => 'allowed', 'retry_after' => null])
        ->and($afterThree['decision']['headers'])->toBe(['X-RateLimit-Limit' => 5, 'X-RateLimit-Remaining' => 1])
        ->and([$fourth->status(), Throttling::headers($fourth)])->toBe([200, array_map(strval(...), $afterThree['decision']['headers'])])
        ->and($afterFive['rules'][0]['counter'])->toBe(['count' => 5, 'remaining' => 0, 'next' => 'limited', 'retry_after' => 62])
        ->and([$afterFive['decision']['outcome'], $afterFive['decision']['rule']])->toBe(['rejected', '1'])
        ->and([$sixth->status(), Throttling::headers($sixth)])->toBe([429, array_map(strval(...), $afterFive['decision']['headers'])]);
})->with(['phpredis', 'predis']);

it('AC-9: changes no counter on Redis', function (string $client): void {
    explainOnRedis($client);
    Throttling::rule(['max_attempts' => 5]);
    Throttling::route('api/x');
    $this->get('api/x')->assertOk();
    $this->get('api/x')->assertOk();

    foreach (range(1, 3) as $explanation) {
        Explanations::json('GET', 'api/x');
    }

    expect(Throttling::headers($this->get('api/x')))->toBe(['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '2']);
})->with(['phpredis', 'predis']);
