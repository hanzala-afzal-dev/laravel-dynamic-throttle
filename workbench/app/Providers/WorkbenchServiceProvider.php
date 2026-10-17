<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Workbench\App\Console\DemoTrafficCommand;

/**
 * The Workbench app as a demo of the dashboard (CONTRIBUTING.md): routes that the
 * demo rules limit, the dashboard's two roles, and a command that sends traffic.
 */
class WorkbenchServiceProvider extends ServiceProvider
{
    /** The demo's one viewer; anyone else, guests too, manages. */
    public const string VIEWER = 'viewer@example.com';

    /**
     * Register services.
     */
    public function register(): void
    {
        // On Redis, as in the Docker demo, the counters get a Redis database of their own,
        // so php artisan cache:clear never resets the limits (docs/guide/health.md).
        if (config('cache.default') === 'redis') {
            config([
                'database.redis.throttle' => [...(array) config('database.redis.default'), 'database' => '2'],
                'dynamic-throttle.redis_connection' => 'throttle',
            ]);
        }
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Sign in at /_workbench/login/viewer@example.com to see the dashboard as a viewer.
        Gate::define('viewDynamicThrottle', fn (?Authenticatable $user = null): bool => true);
        Gate::define('manageDynamicThrottle', fn (?Authenticatable $user = null): bool => data_get($user, 'email') !== self::VIEWER);

        // Outside the web group, as an API is: no session, no CSRF token.
        Route::middleware('dynamic.throttle')->group(function (): void {
            Route::get('api/orders', fn (): array => ['orders' => []]);
            Route::get('api/search', fn (): array => ['results' => []]);
            Route::get('api/export', fn (): array => ['export' => 'queued']);
        });

        if ($this->app->runningInConsole()) {
            $this->commands([DemoTrafficCommand::class]);
        }
    }
}
