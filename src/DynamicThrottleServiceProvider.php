<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Support\ConfigMerger;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Foundation\CachesConfiguration;
use Illuminate\Foundation\Console\AboutCommand;
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

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

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
}
