<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Compiler\ManifestObserver;
use Afzal\DynamicThrottle\Console\CompileCommand;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Http\IdentitySettings;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Support\ConfigMerger;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Composer\InstalledVersions;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;

final class DynamicThrottleServiceProvider extends ServiceProvider
{
    public const string PACKAGE = 'hanzala-afzal/laravel-dynamic-throttle';

    public function register(): void
    {
        $this->mergePackageConfig();

        $this->app->singleton(Clock::class, LaravelClock::class);

        $this->app->bind(KeyBuilder::class, function (Application $app): KeyBuilder {
            $prefix = $app->make('config')->get('dynamic-throttle.prefix');

            return new KeyBuilder(is_string($prefix) ? $prefix : 'dt:');
        });

        // Scoped: Octane builds it once per request, so config changes apply on the next request.
        $this->app->scoped(LimiterStore::class, fn (Application $app): LimiterStore => $app->make(LimiterStoreFactory::class)->make());

        // Exemption values are hashed as requests hash them (ADR-0024).
        $this->app->bind(ManifestCompiler::class, fn (Application $app): ManifestCompiler => new ManifestCompiler(
            $this->manifestCache($app),
            $app->make(KeyBuilder::class),
            hasher: $app->make(IdentityHasher::class),
        ));

        // Scoped, so each Octane request registers its rebuild on its own app. The rules
        // themselves stay in process memory across requests (ManifestMemory).
        $this->app->scoped(ManifestRepository::class, fn (Application $app): ManifestRepository => new ManifestRepository(
            $this->manifestCache($app),
            $app->make(KeyBuilder::class),
            $app->make(Clock::class),
            $app->make(ManifestCompiler::class),
            $app,
            $this->refreshSeconds($app),
        ));

        // The cap lives in the evaluator, which knows which rules count a request (ADR-0025).
        $this->app->scoped(RuleEvaluator::class, fn (Application $app): RuleEvaluator => new RuleEvaluator(
            $app->make(LimiterStore::class),
            $this->maxRulesPerRequest($app),
        ));

        // Apps register their custom scopes once, at boot, so they live for the worker.
        $this->app->singleton(DynamicThrottle::class);

        $this->app->singleton(IdentityHasher::class, function (Application $app): IdentityHasher {
            $key = $app->make('config')->get('app.key');

            return new IdentityHasher(is_string($key) ? $key : null);
        });

        $this->app->bind(IdentitySettings::class, fn (Application $app): IdentitySettings => new IdentitySettings(
            $this->ipv6Prefix($app),
            $this->guards($app),
        ));
    }

    /**
     * Like mergeConfigFrom(), but new nested defaults reach configs published by
     * older versions, and lists stay as the app wrote them (ADR-0020 point 10).
     */
    private function mergePackageConfig(): void
    {
        if ($this->app instanceof CachesConfiguration && $this->app->configurationIsCached()) {
            return;
        }

        /** @var array<string, mixed> $defaults */
        $defaults = require __DIR__.'/../config/dynamic-throttle.php';
        $config = $this->app->make('config');
        $app = $config->get('dynamic-throttle', []);

        $config->set('dynamic-throttle', ConfigMerger::merge($defaults, is_array($app) ? $app : []));
    }

    /**
     * The manifest lives in the same cache store as the counters (ADR-0005 point 6).
     */
    private function manifestCache(Application $app): CacheRepository
    {
        $store = $app->make('config')->get('dynamic-throttle.store');

        return $app->make('cache')->store(is_string($store) && $store !== '' ? $store : null);
    }

    private function refreshSeconds(Application $app): int
    {
        $seconds = $app->make('config')->get('dynamic-throttle.manifest.refresh_seconds');

        return is_int($seconds) && $seconds >= 0 ? $seconds : 2;
    }

    private function maxRulesPerRequest(Application $app): int
    {
        $cap = $app->make('config')->get('dynamic-throttle.matching.max_rules_per_request');

        return is_int($cap) && $cap >= 1 ? $cap : 5;
    }

    private function ipv6Prefix(Application $app): int
    {
        $prefix = $app->make('config')->get('dynamic-throttle.scopes.ip.ipv6_prefix');

        return is_int($prefix) && $prefix >= 1 && $prefix <= 128 ? $prefix : 64;
    }

    /**
     * scopes.user.guards: a non-empty list of guard names, or null for the guard the
     * request authenticated with. Anything else counts as null.
     *
     * @return list<string>|null
     */
    private function guards(Application $app): ?array
    {
        $guards = $app->make('config')->get('dynamic-throttle.scopes.user.guards');

        if (! is_array($guards) || $guards === [] || ! array_is_list($guards)) {
            return null;
        }

        $names = array_filter($guards, fn (mixed $guard): bool => is_string($guard) && $guard !== '');

        return count($names) === count($guards) ? array_values($names) : null;
    }

    public function boot(): void
    {
        // Admins edit rules and exemptions from the dashboard too, so this is not console-only.
        Rule::observe(ManifestObserver::class);
        Exemption::observe(ManifestObserver::class);

        // A route middleware, attached nowhere until the app says so (ADR-0004, ADR-0020 point 11).
        $this->callAfterResolving(Router::class, function (Router $router): void {
            $router->aliasMiddleware(DynamicThrottleMiddleware::ALIAS, DynamicThrottleMiddleware::class);
        });

        // Once every provider has booted, so middleware.after can name aliases that other
        // packages register. Through the kernel, which copies its lists to the router each
        // time they change, so a later change there keeps these.
        $this->app->booted(function (): void {
            $this->callAfterResolving(HttpKernel::class, function (HttpKernel $kernel): void {
                // Another kernel gets neither: the docs show the manual setup (ADR-0004's fallback).
                if ($kernel instanceof Kernel) {
                    $this->attachToGroups($kernel);
                    $this->sortAfterAuthentication($kernel);
                }
            });
        });

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([CompileCommand::class]);

        // Heals a manifest the cache lost (SPEC-006). manifest.schedule turns it off.
        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            if ($this->app->make('config')->get('dynamic-throttle.manifest.schedule') !== false) {
                $schedule->command('dynamic-throttle:compile')->everyFiveMinutes();
            }
        });

        $this->publishes([
            __DIR__.'/../config/dynamic-throttle.php' => $this->app->configPath('dynamic-throttle.php'),
        ], 'dynamic-throttle-config');

        // Published, never loaded: the app owns its schema (ADR-0020 point 7).
        $this->publishesMigrations([
            __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
        ], 'dynamic-throttle-migrations');

        AboutCommand::add('Dynamic Throttle', fn (): array => [
            // getPrettyVersion() throws when Composer does not know the package.
            'Version' => InstalledVersions::isInstalled(self::PACKAGE)
                ? InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown'
                : 'unknown',
            'Enabled' => AboutCommand::format(
                config('dynamic-throttle.enabled'),
                console: fn (mixed $enabled): string => $enabled === true
                    ? '<fg=green;options=bold>ENABLED</>'
                    : '<fg=yellow;options=bold>DISABLED</>',
            ),
        ]);
    }

    /**
     * middleware.auto_attach. A group the app has not defined is skipped, never thrown
     * on, so a config mistake cannot take the app down (ADR-0020 point 1).
     */
    private function attachToGroups(Kernel $kernel): void
    {
        $groups = $kernel->getMiddlewareGroups();

        foreach ($this->configList('dynamic-throttle.middleware.auto_attach') as $group) {
            if (isset($groups[$group])) {
                $kernel->appendMiddlewareToGroup($group, DynamicThrottleMiddleware::class);
            }
        }
    }

    /**
     * Sorts the middleware after Laravel's authentication and after middleware.after,
     * wherever a group lists it, so user rules see the user (ADR-0004 point 2, ADR-0020
     * point 4). A middleware the priority list lacks goes right before it. An app that
     * placed the middleware in the list itself keeps that place.
     */
    private function sortAfterAuthentication(Kernel $kernel): void
    {
        $after = [AuthenticatesRequests::class, ...$this->middlewareClasses($this->configList('dynamic-throttle.middleware.after'))];
        $priority = $kernel->getMiddlewarePriority();

        $kernel->addToMiddlewarePriorityAfter($after, DynamicThrottleMiddleware::class);

        foreach ($after as $middleware) {
            if (! in_array($middleware, $priority, true)) {
                $kernel->addToMiddlewarePriorityBefore(DynamicThrottleMiddleware::class, $middleware);
            }
        }
    }

    /**
     * Middleware as the priority list names them: aliases as their classes, without
     * parameters such as the ":api" of "auth:api".
     *
     * @param  list<string>  $names
     * @return list<string>
     */
    private function middlewareClasses(array $names): array
    {
        $aliases = $this->app->make(Router::class)->getMiddleware();
        $classes = [];

        foreach ($names as $name) {
            $name = explode(':', $name, 2)[0];
            $class = $aliases[$name] ?? $name;

            if (is_string($class)) {
                $classes[] = $class;
            }
        }

        return $classes;
    }

    /**
     * A config list's non-empty strings. Anything else in it is skipped.
     *
     * @return list<string>
     */
    private function configList(string $key): array
    {
        $values = $this->app->make('config')->get($key);

        return is_array($values)
            ? array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''))
            : [];
    }
}
