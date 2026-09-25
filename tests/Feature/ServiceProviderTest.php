<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle as DynamicThrottleFacade;
use Afzal\DynamicThrottle\Http\IdentitySettings;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;

/*
| Phase 0 smoke tests: the package boots inside a Laravel application.
*/

it('boots the service provider', function (): void {
    expect(app()->providerIsLoaded(DynamicThrottleServiceProvider::class))->toBeTrue();
});

it('merges the package config under the dynamic-throttle key', function (): void {
    expect(config('dynamic-throttle.enabled'))->toBeTrue();
});

it('publishes the config file with the dynamic-throttle-config tag', function (): void {
    // Checks the mapping instead of copying the file: a copy in Testbench's shared
    // config folder breaks other workers under --parallel. scripts/verify-install.sh
    // publishes for real into a fresh Laravel app.
    $paths = ServiceProvider::pathsToPublish(DynamicThrottleServiceProvider::class, 'dynamic-throttle-config');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath(__DIR__.'/../../config/dynamic-throttle.php'))
        ->and(array_values($paths))->toBe([config_path('dynamic-throttle.php')]);
});

it('reports its version and kill switch in php artisan about', function (): void {
    Artisan::call('about', ['--json' => true]);

    /** @var array<string, array<string, mixed>> $about */
    $about = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($about)->toHaveKey('dynamic_throttle')
        ->and($about['dynamic_throttle'])->toHaveKeys(['version', 'enabled'])
        ->and($about['dynamic_throttle']['enabled'])->toBeTrue();
});

/*
| SPEC-005 AC-6: the provider merges with ConfigMerger (ADR-0020 point 10).
*/

it('AC-6: gives a config published before a section existed that section\'s defaults', function (): void {
    config()->set('dynamic-throttle', [
        'enabled' => true,
        'driver' => 'auto',
        'store' => null,
        'redis_connection' => null,
        'prefix' => 'app:',
    ]);

    app()->register(DynamicThrottleServiceProvider::class, force: true);

    expect(config('dynamic-throttle.prefix'))->toBe('app:')
        ->and(config()->has('dynamic-throttle.database.connection'))->toBeTrue()
        ->and(config('dynamic-throttle.database.connection'))->toBeNull();
});

it('AC-6: fills an empty section with its defaults, which mergeConfigFrom would not', function (): void {
    config()->set('dynamic-throttle', ['database' => []]);

    app()->register(DynamicThrottleServiceProvider::class, force: true);

    expect(config()->has('dynamic-throttle.database.connection'))->toBeTrue()
        ->and(config('dynamic-throttle.prefix'))->toBe('dt:');
});

it('AC-8: publishes the migrations and never loads them', function (): void {
    $package = __DIR__.'/../../database/migrations';
    $paths = ServiceProvider::pathsToPublish(DynamicThrottleServiceProvider::class, 'dynamic-throttle-migrations');

    expect($paths)->toHaveCount(1)
        ->and(realpath((string) array_key_first($paths)))->toBe(realpath($package))
        ->and(array_values($paths))->toBe([database_path('migrations')])
        ->and(array_map(realpath(...), app('migrator')->paths()))->not->toContain(realpath($package));
});

/*
| SPEC-007 AC-7 and ADR-0025: the container's evaluator takes its cap from the
| config, and a published config can hold anything.
*/

/**
 * Evaluates enforcing rules 1 to $count for one client with the container's
 * evaluator, on the array store.
 */
function evaluateWithContainer(int $count): Evaluation
{
    config()->set('dynamic-throttle.store', 'array');
    $rules = array_map(fn (int $id): CompiledRule => CompiledRule::fromArray(ManifestFixtures::rule(['id' => (string) $id])), range(1, $count));

    return app(RuleEvaluator::class)->evaluate($rules, new class implements IdentityResolver
    {
        public function exemption(CompiledRule $rule): ?string
        {
            return null;
        }

        public function resolve(CompiledRule $rule): string
        {
            return hash('sha256', 'client');
        }
    });
}

it('AC-7: caps the evaluator at matching.max_rules_per_request (ADR-0025)', function (): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', 2);

    $evaluation = evaluateWithContainer(3);

    expect([count($evaluation->attempts), count($evaluation->ignored)])->toBe([2, 1]);
});

it('AC-7: falls back to 5 rules for a published cap below 1 or not an integer', function (mixed $cap): void {
    config()->set('dynamic-throttle.matching.max_rules_per_request', $cap);

    $evaluation = evaluateWithContainer(7);

    expect([count($evaluation->attempts), count($evaluation->ignored)])->toBe([5, 2]);
})->with([
    'zero' => [0],
    'negative' => [-1],
    'a fraction' => [2.5],
    'text' => ['many'],
    'null' => [null],
]);

/*
| SPEC-008: one scope registry for the app, behind the facade, and the scopes
| config as the resolver sees it. A published config can hold anything (AC-17).
*/

it('SPEC-008: shares one scope registry across the app, behind the facade', function (): void {
    expect(app(DynamicThrottle::class))->toBe(app(DynamicThrottle::class))
        ->and(DynamicThrottleFacade::getFacadeRoot())->toBe(app(DynamicThrottle::class));
});

it('AC-17: takes the published IPv6 prefix and guard list', function (): void {
    config()->set('dynamic-throttle.scopes.ip.ipv6_prefix', 48);
    config()->set('dynamic-throttle.scopes.user.guards', ['sanctum', 'web']);

    $settings = app(IdentitySettings::class);

    expect([$settings->ipv6Prefix, $settings->guards])->toBe([48, ['sanctum', 'web']]);
});

it('AC-17: groups IPv6 by /64 when the published prefix is outside 1 to 128 or not an integer', function (mixed $prefix): void {
    config()->set('dynamic-throttle.scopes.ip.ipv6_prefix', $prefix);

    expect(app(IdentitySettings::class)->ipv6Prefix)->toBe(64);
})->with([
    'zero' => [0],
    'too long' => [129],
    'a fraction' => [2.5],
    'text' => ['wide'],
    'null' => [null],
]);

it('AC-17: uses the authenticated guard when the published guards are not a list of names', function (mixed $guards): void {
    config()->set('dynamic-throttle.scopes.user.guards', $guards);

    expect(app(IdentitySettings::class)->guards)->toBeNull();
})->with([
    'null' => [null],
    'a string' => ['web'],
    'an empty list' => [[]],
    'a list with a number' => [['web', 5]],
    'a map' => [['first' => 'web']],
]);
