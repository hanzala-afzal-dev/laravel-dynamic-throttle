<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Enums\FailurePolicy;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Support\ApcuFailureMemory;
use Afzal\DynamicThrottle\Tests\Support\FailingStore;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Support\Carbon;

pest()->extend(TestCase::class);

/*
| SPEC-012 AC-10 and AC-11 through the middleware, with APCu itself: the
| workers of one app share the cooldown, and the other apps of the server,
| whose PHP-FPM pools share APCu, never see it.
*/

beforeEach(function (): void {
    if (! ApcuFailureMemory::available()) {
        $this->markTestSkipped('APCu is off. Run composer test:apcu.');
    }

    apcu_clear_cache();
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-10: lets another worker of the app see the cooldown through APCu', function (): void {
    Throttling::rule(['on_failure' => FailurePolicy::Closed]);
    Throttling::route('api/x');
    $store = FailingStore::down();
    app()->instance(LimiterStore::class, $store);
    $this->get('api/x');

    // Another worker: nothing in its process memory, and its own evaluator.
    app()->instance(ProcessFailureMemory::class, new ProcessFailureMemory);
    app()->forgetInstance(RuleEvaluator::class);
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0 + 3));
    $response = $this->get('api/x');

    expect([$response->status(), $response->headers->get('Retry-After'), $store->attempts])->toBe([503, '7', 1]);
});

it('AC-11: never sees the cooldown of another app of the server, even under the same prefix', function (): void {
    (new ApcuFailureMemory(app(KeyBuilder::class), 'another-app'))->startCooldown(FakeClock::T0 * 1_000_000, 10);
    Throttling::rule(['on_failure' => FailurePolicy::Closed, 'max_attempts' => 5]);
    Throttling::route('api/x');

    $response = $this->get('api/x');

    // Counted on its own healthy store, as if no app had failed.
    expect([$response->status(), Throttling::headers($response)])->toBe([200, ['X-RateLimit-Limit' => '5', 'X-RateLimit-Remaining' => '4']]);
});
