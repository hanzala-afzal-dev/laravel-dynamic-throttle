<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Composer\InstalledVersions;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;

final class DynamicThrottleServiceProvider extends ServiceProvider
{
    public const string PACKAGE = 'hanzala-afzal/laravel-dynamic-throttle';

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/dynamic-throttle.php', 'dynamic-throttle');
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
