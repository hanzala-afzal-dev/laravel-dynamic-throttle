<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Compiler\LastCompile;
use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Compiler\ManifestObserver;
use Afzal\DynamicThrottle\Console\BypassCommand;
use Afzal\DynamicThrottle\Console\CompileCommand;
use Afzal\DynamicThrottle\Console\DisableRuleCommand;
use Afzal\DynamicThrottle\Console\EnableRuleCommand;
use Afzal\DynamicThrottle\Console\ExplainCommand;
use Afzal\DynamicThrottle\Console\FlushMetricsCommand;
use Afzal\DynamicThrottle\Console\HealthCommand;
use Afzal\DynamicThrottle\Console\ListCommand;
use Afzal\DynamicThrottle\Console\PruneCommand;
use Afzal\DynamicThrottle\Console\ScaleCommand;
use Afzal\DynamicThrottle\Console\StatusCommand;
use Afzal\DynamicThrottle\Dashboard\DashboardRoutes;
use Afzal\DynamicThrottle\Dashboard\SecurityHeaders;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\FailureMemory;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\Failure\ProcessFailureMemory;
use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Telemetry\Sampler;
use Afzal\DynamicThrottle\Http\IdentitySettings;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Http\RequestIdentityResolver;
use Afzal\DynamicThrottle\Manifest\Contracts\LocalManifestCache;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Models\Rule;
use Afzal\DynamicThrottle\Models\RuleObserver;
use Afzal\DynamicThrottle\Models\Setting;
use Afzal\DynamicThrottle\Support\ApcuFailureMemory;
use Afzal\DynamicThrottle\Support\ApcuManifestCache;
use Afzal\DynamicThrottle\Support\ConfigMerger;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\Explainer;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Support\LazyManifestRebuilder;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Afzal\DynamicThrottle\Support\UnavailableLimiterStore;
use Composer\InstalledVersions;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use OutOfBoundsException;
use Throwable;

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

        // In the counters' store, on their driver, scoped as they are (SPEC-015).
        $this->app->scoped(TelemetryBuffer::class, fn (Application $app): TelemetryBuffer => $app->make(LimiterStoreFactory::class)->telemetry());

        $this->registerRequestPath();

        // Scoped, so a change reads back its own compile, never another request's (SPEC-013).
        $this->app->scoped(LastCompile::class);

        // Exemption values are hashed as requests hash them (ADR-0024).
        $this->app->bind(ManifestCompiler::class, fn (Application $app): ManifestCompiler => new ManifestCompiler(
            $this->manifestCache($app),
            $app->make(KeyBuilder::class),
            hasher: $app->make(IdentityHasher::class),
        ));

        // Scoped, so each Octane request registers its rebuild on its own app. The rules
        // themselves stay in process memory across requests (ManifestMemory).
        $this->app->scoped(ManifestRepository::class, function (Application $app): ManifestRepository {
            $cache = $this->manifestCache($app);
            $keys = $app->make(KeyBuilder::class);
            $refreshSeconds = $this->refreshSeconds($app);

            return new ManifestRepository(
                $cache,
                $keys,
                $app->make(Clock::class),
                new LazyManifestRebuilder($app),
                $app,
                $refreshSeconds,
                $this->localManifestCache($app, $keys, $refreshSeconds),
                $this->sharedFailureMemory($app),
            );
        });

        // The cap lives in the evaluator, which knows which rules count a request (ADR-0025).
        $this->app->scoped(RuleEvaluator::class, fn (Application $app): RuleEvaluator => new RuleEvaluator(
            $this->limiterStore($app),
            $this->maxRulesPerRequest($app),
            $app->make(Cooldown::class),
        ));

        // On the counter store requests use, with the same fallback, and the same cap (SPEC-014).
        $this->app->bind(Explainer::class, function (Application $app): Explainer {
            $store = $this->limiterStore($app);

            return new Explainer(
                $app,
                $app->make('config'),
                $this->manifestCache($app),
                $app->make(KeyBuilder::class),
                $app->make(Clock::class),
                $store,
                new RuleEvaluator($store, $this->maxRulesPerRequest($app)),
                $this->maxRulesPerRequest($app),
                $this->cooldownSeconds($app),
            );
        });

        // Made now, at boot, so it outlives each request on Octane and in queue workers (SPEC-012).
        $this->app->instance(ProcessFailureMemory::class, new ProcessFailureMemory);

        $this->app->bind(Cooldown::class, fn (Application $app): Cooldown => new Cooldown(
            $app->make(Clock::class),
            $this->cooldownSeconds($app),
            ...$this->failureMemories($app),
        ));

        // Reports are throttled where the cooldown lives, for as long (SPEC-012 decision 5).
        $this->app->bind(ReportThrottle::class, fn (Application $app): ReportThrottle => new ReportThrottle(
            $app->make(Clock::class),
            $this->cooldownSeconds($app),
            ...$this->failureMemories($app),
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
     * What every limited request builds, bound explicitly: Laravel's container would
     * reflect on each class again on every request (the Phase 2 exit review). The
     * middleware is built twice per request, for handle() and for terminate().
     */
    private function registerRequestPath(): void
    {
        $this->app->bind(DynamicThrottleMiddleware::class, fn (Application $app): DynamicThrottleMiddleware => new DynamicThrottleMiddleware(
            $app,
            $app->make('config'),
            $app->make(Clock::class),
            $app->make(DynamicThrottle::class),
        ));

        // make(RequestIdentityResolver::class, ['request' => ..., 'exemptions' => ...]), as the middleware does.
        $this->app->bind(RequestIdentityResolver::class, function (Application $app, array $parameters): RequestIdentityResolver {
            $request = $parameters['request'] ?? null;
            $exemptions = $parameters['exemptions'] ?? null;

            return new RequestIdentityResolver(
                $request instanceof Request ? $request : $app->make('request'),
                $app->make('auth'),
                $app->make(DynamicThrottle::class),
                $app,
                $app->make(IdentityHasher::class),
                $app->make(IdentitySettings::class),
                $app->make(Clock::class),
                $exemptions instanceof ExemptionSet ? $exemptions : new ExemptionSet,
            );
        });

        // terminate() builds it for each request it records (SPEC-015).
        $this->app->bind(Sampler::class, fn (Application $app): Sampler => new Sampler($this->sampleRate($app)));

        $this->app->bind(LimiterStoreFactory::class, fn (Application $app): LimiterStoreFactory => new LimiterStoreFactory(
            $app->make('config'),
            $app->make('cache'),
            $app->make('redis'),
            $app->make(KeyBuilder::class),
            $app->make(Clock::class),
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

    /**
     * L1 (ADR-0007, SPEC-028): the manifest in APCu, where a server's PHP-FPM workers
     * share it. Off when every request reads the store, and under fake(), whose
     * manifest APCu would carry from one test to the next.
     */
    private function localManifestCache(Application $app, KeyBuilder $keys, int $refreshSeconds): ?LocalManifestCache
    {
        if ($refreshSeconds === 0 || ! ApcuManifestCache::available() || $app->make(DynamicThrottle::class)->isFaked()) {
            return null;
        }

        return new ApcuManifestCache($keys, $this->appId($app), $this->installedVersion());
    }

    /**
     * The app among those of the server, by its path: the PHP-FPM pools of a server
     * share APCu (SPEC-012).
     */
    private function appId(Application $app): string
    {
        return hash('xxh128', $app->basePath());
    }

    /**
     * The package's commit, as Composer installed it, else its version (SPEC-028 decision
     * 3). Composer's list of installed packages is in OPcache, so this costs microseconds.
     */
    private function installedVersion(): string
    {
        try {
            return InstalledVersions::getReference(self::PACKAGE) ?? InstalledVersions::getPrettyVersion(self::PACKAGE) ?? 'unknown';
        } catch (OutOfBoundsException) {
            // Not installed through Composer.
            return 'unknown';
        }
    }

    private function refreshSeconds(Application $app): int
    {
        return ConfigValues::refreshSeconds($app->make('config')->get('dynamic-throttle.manifest.refresh_seconds'));
    }

    private function maxRulesPerRequest(Application $app): int
    {
        return ConfigValues::maxRulesPerRequest($app->make('config')->get('dynamic-throttle.matching.max_rules_per_request'));
    }

    /**
     * The counter store. One that cannot be built, such as for an unknown driver, is
     * a store that fails every call, so the failure policy applies to it. Strict mode
     * rethrows the config mistake instead (SPEC-012).
     */
    private function limiterStore(Application $app): LimiterStore
    {
        try {
            return $app->make(LimiterStore::class);
        } catch (Throwable $exception) {
            if ($app->make(DynamicThrottle::class)->isStrict()) {
                throw $exception;
            }

            return new UnavailableLimiterStore($exception);
        }
    }

    private function cooldownSeconds(Application $app): int
    {
        return ConfigValues::cooldownSeconds($app->make('config')->get('dynamic-throttle.failure.cooldown_seconds'));
    }

    private function sampleRate(Application $app): float
    {
        return ConfigValues::sampleRate($app->make('config')->get('dynamic-throttle.telemetry.sample_rate'));
    }

    private function retentionHours(Application $app): int
    {
        return ConfigValues::retentionHours($app->make('config')->get('dynamic-throttle.telemetry.retention_hours'));
    }

    /**
     * Where failures are remembered (SPEC-012 decision 1): in the process, and in APCu
     * when the extension is enabled, where a server's PHP-FPM workers share them.
     *
     * @return list<FailureMemory>
     */
    private function failureMemories(Application $app): array
    {
        $shared = $this->sharedFailureMemory($app);

        return $shared instanceof FailureMemory
            ? [$app->make(ProcessFailureMemory::class), $shared]
            : [$app->make(ProcessFailureMemory::class)];
    }

    /**
     * APCu, where a server's PHP-FPM workers share what failed, or null without it.
     */
    private function sharedFailureMemory(Application $app): ?FailureMemory
    {
        // fake()'s failures stay in the process: APCu would carry them from one test to the next.
        if (! ApcuFailureMemory::available() || $app->make(DynamicThrottle::class)->isFaked()) {
            return null;
        }

        try {
            return new ApcuFailureMemory($app->make(KeyBuilder::class), $this->appId($app));
        } catch (Throwable) {
            // An invalid prefix, reported where the counter store is built. The process still remembers.
            return null;
        }
    }

    private function ipv6Prefix(Application $app): int
    {
        return ConfigValues::ipv6Prefix($app->make('config')->get('dynamic-throttle.scopes.ip.ipv6_prefix'));
    }

    /**
     * @return list<string>|null
     */
    private function guards(Application $app): ?array
    {
        return ConfigValues::guards($app->make('config')->get('dynamic-throttle.scopes.user.guards'));
    }

    public function boot(): void
    {
        // Admins edit rules, exemptions and settings from the dashboard too, so this is not console-only.
        Rule::observe(ManifestObserver::class);
        Exemption::observe(ManifestObserver::class);
        Setting::observe(ManifestObserver::class);
        // RuleChanged, for integrations (SPEC-015).
        Rule::observe(RuleObserver::class);

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
                    $this->wireMiddleware($kernel, $this->app->make(Router::class));
                }
            });
        });

        // The dashboard and its API (SPEC-018), unless the app cached its routes.
        if (! $this->app->routesAreCached()) {
            DashboardRoutes::register($this->app->make(Router::class), $this->app->make('config'));
        }

        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            CompileCommand::class,
            StatusCommand::class,
            BypassCommand::class,
            ScaleCommand::class,
            ListCommand::class,
            DisableRuleCommand::class,
            EnableRuleCommand::class,
            ExplainCommand::class,
            FlushMetricsCommand::class,
            PruneCommand::class,
            HealthCommand::class,
        ]);

        $this->callAfterResolving(Schedule::class, function (Schedule $schedule): void {
            $config = $this->app->make('config');

            // Heals a manifest the cache lost (SPEC-006). manifest.schedule turns it off.
            if ($config->get('dynamic-throttle.manifest.schedule') !== false) {
                $schedule->command('dynamic-throttle:compile')->everyFiveMinutes();
            }

            // Moves the numbers to the database before the store lets them expire, and
            // deletes the old ones (SPEC-015). A flush that dies holds the lock 10 minutes
            // at most, well within the 2 hours the store keeps the numbers.
            if ($config->get('dynamic-throttle.telemetry.enabled') !== false && $config->get('dynamic-throttle.telemetry.schedule') !== false) {
                $schedule->command('dynamic-throttle:flush-metrics')->everyMinute()->withoutOverlapping(10);
                $schedule->command('dynamic-throttle:prune', ['--hours' => $this->retentionHours($this->app)])->daily();
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
     * middleware.auto_attach, and the middleware's place in the priority list.
     *
     * The kernel writes all its lists over the router's each time one of them changes,
     * so it first takes the router's, all read before the first write: what other
     * providers changed there while booting, a group, an alias or the priority list,
     * stays as they left it (SPEC-010 AC-21).
     */
    private function wireMiddleware(Kernel $kernel, Router $router): void
    {
        $aliases = $router->getMiddleware();
        $groups = $router->getMiddlewareGroups();
        $priority = $this->prioritized($router->middlewarePriority, $aliases);

        // A group the app has not defined is skipped, never thrown on, so a config mistake
        // cannot take the app down (ADR-0020 point 1).
        foreach ($this->configList('dynamic-throttle.middleware.auto_attach') as $group) {
            if (isset($groups[$group]) && is_array($groups[$group]) && ! in_array(DynamicThrottleMiddleware::class, $groups[$group], true)) {
                $groups[$group][] = DynamicThrottleMiddleware::class;
            }
        }

        $kernel->setMiddlewareAliases($aliases);
        $kernel->setMiddlewareGroups($groups);
        $kernel->setMiddlewarePriority($priority);
    }

    /**
     * The priority list, with the middleware after Laravel's authentication, its basic
     * authentication included, and after middleware.after, so user rules see the user
     * wherever a group lists it (ADR-0004 point 2, ADR-0020 point 4). A middleware the
     * list lacks goes right before it. An app that placed the middleware in the list
     * itself keeps that place.
     *
     * The dashboard's headers come first.
     *
     * Computed here: the kernel's addToMiddlewarePriorityAfter() places it before an
     * entry that sits right after another on Laravel 12.61.1.
     *
     * @param  array<array-key, mixed>  $priority
     * @param  array<array-key, mixed>  $aliases
     * @return list<string>
     */
    private function prioritized(array $priority, array $aliases): array
    {
        $priority = array_values(array_filter($priority, is_string(...)));
        $after = array_values(array_unique([
            AuthenticatesRequests::class,
            AuthenticateWithBasicAuth::class,
            ...$this->middlewareClasses($this->configList('dynamic-throttle.middleware.after'), $aliases),
        ]));

        if (! in_array(DynamicThrottleMiddleware::class, $priority, true)) {
            $present = array_keys(array_intersect($priority, $after));
            array_splice($priority, $present === [] ? count($priority) : max($present) + 1, 0, [DynamicThrottleMiddleware::class]);
        }

        array_splice($priority, (int) array_search(DynamicThrottleMiddleware::class, $priority, true), 0, array_values(array_diff($after, $priority)));

        // The dashboard's headers before every other middleware of its routes, so the responses
        // Laravel's own middleware answers with, such as throttle's 429, carry them too (SPEC-018).
        return in_array(SecurityHeaders::class, $priority, true) ? $priority : [SecurityHeaders::class, ...$priority];
    }

    /**
     * Middleware as the priority list names them: aliases as their classes, without
     * parameters such as the ":api" of "auth:api".
     *
     * @param  list<string>  $names
     * @param  array<array-key, mixed>  $aliases  the router's
     * @return list<string>
     */
    private function middlewareClasses(array $names, array $aliases): array
    {
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
