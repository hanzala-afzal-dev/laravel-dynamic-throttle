<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\FakeClock;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;

/*
| SPEC-010 AC-5 to AC-7: the requests the middleware lets through untouched,
| before it reads any rule. Each test also shows a request the same rule does
| limit, so none can pass because the middleware never ran.
*/

beforeEach(function (): void {
    Throttling::prepare();
    $this->travelTo(Carbon::createFromTimestamp(FakeClock::T0));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-5: with enabled off, never limits, sets no headers, and reads nothing from the cache', function (): void {
    Throttling::rule(['max_attempts' => 1]);
    Throttling::route('api/x');
    $reads = [];
    Event::listen(RetrievingKey::class, function (RetrievingKey $event) use (&$reads): void {
        $reads[] = $event->key;
    });

    config()->set('dynamic-throttle.enabled', false);
    $off = [$this->get('api/x'), $this->get('api/x')];
    $readsWhileOff = $reads;
    config()->set('dynamic-throttle.enabled', true);
    $on = $this->get('api/x');

    expect(array_map(fn (TestResponse $response): int => $response->status(), $off))->toBe([200, 200])
        ->and(array_map(Throttling::headers(...), $off))->toBe([[], []])
        ->and($readsWhileOff)->toBe([])
        // Switched back on, the same request reads the manifest, and is counted.
        ->and($reads)->toContain(app(KeyBuilder::class)->manifest())
        ->and(Throttling::headers($on))->toBe(['X-RateLimit-Limit' => '1', 'X-RateLimit-Remaining' => '0']);
});

it('AC-6: never limits the paths ADR-0020 excludes, under a * rule', function (string $path): void {
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route($path);
    Throttling::route('api/x');

    $excluded = [$this->get($path), $this->get($path)];

    expect(array_map(fn (TestResponse $response): int => $response->status(), $excluded))->toBe([200, 200])
        ->and(array_map(Throttling::headers(...), $excluded))->toBe([[], []])
        ->and([$this->get('api/x')->status(), $this->get('api/x')->status()])->toBe([200, 429]);
})->with([
    'livewire/update',
    'horizon/api/stats',
    'up',
    // Decision 5: the admin tools' root pages, and Livewire 4's endpoint.
    'horizon',
    'telescope',
    'nova',
    'livewire-3f2a9c1d/update',
]);

it('AC-6: limits a path once the app removes its pattern from except', function (): void {
    config()->set('dynamic-throttle.except', ['horizon/*', 'up']);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route('livewire/update');

    expect([$this->get('livewire/update')->status(), $this->get('livewire/update')->status()])->toBe([200, 429]);
});

it('AC-6: ignores a leading slash in an except pattern, as in rule patterns', function (): void {
    config()->set('dynamic-throttle.except', ['/reports/*']);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route('reports/daily');
    Throttling::route('api/x');

    expect([$this->get('reports/daily')->status(), $this->get('reports/daily')->status()])->toBe([200, 200])
        ->and([$this->get('api/x')->status(), $this->get('api/x')->status()])->toBe([200, 429]);
});

it('AC-7: never limits the package\'s own routes, even with an empty except, and limits a route named like them', function (): void {
    config()->set('dynamic-throttle.except', []);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Throttling::route('dynamic-throttle')->name('dynamic-throttle.dashboard');
    Throttling::route('lookalike')->name('dynamic-throttled.home');

    $own = [$this->get('dynamic-throttle'), $this->get('dynamic-throttle')];

    expect(array_map(fn (TestResponse $response): int => $response->status(), $own))->toBe([200, 200])
        ->and(array_map(Throttling::headers(...), $own))->toBe([[], []])
        ->and([$this->get('lookalike')->status(), $this->get('lookalike')->status()])->toBe([200, 429]);
});
