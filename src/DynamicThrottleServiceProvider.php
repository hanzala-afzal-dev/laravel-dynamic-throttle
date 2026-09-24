<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Support\LaravelClock;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Composer\InstalledVersions;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

final class DynamicThrottleServiceProvider extends ServiceProvider
{
    public const string PACKAGE = 'hanzala-afzal/laravel-dynamic-throttle';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dynamic-throttle.php', 'dynamic-throttle');

        $this->app->singleton(Clock::class, LaravelClock::class);

        $this->app->bind(KeyBuilder::class, function (Application $app): KeyBuilder {
            $prefix = $app->make('config')->get('dynamic-throttle.prefix');

            return new KeyBuilder(is_string($prefix) ? $prefix : 'dt:');
        });

        // Scoped: Octane builds it once per request, so config changes apply on the next request.
        $this->app->scoped(LimiterStore::class, fn (Application $app): LimiterStore => $app->make(LimiterStoreFactory::class)->make());
    }

    public function boot(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->publishes([
            __DIR__.'/../config/dynamic-throttle.php' => $this->app->configPath('dynamic-throttle.php'),
        ], 'dynamic-throttle-config');

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
