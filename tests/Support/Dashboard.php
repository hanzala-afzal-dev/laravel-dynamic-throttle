<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Http\Dashboard\DashboardRoutes;
use Closure;
use Illuminate\Routing\RouteCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * The dashboard's routes and gates, as SPEC-018's tests set them up.
 */
final class Dashboard
{
    /** A route in the dashboard's group that changes something, until SPEC-017's exist. */
    public const string WRITE_PATH = 'api/test-write';

    /**
     * Sets dashboard.* config, then registers the routes again from nothing, as an app
     * that boots with that config does.
     *
     * @param  array<string, mixed>  $config
     */
    public static function boot(array $config = []): void
    {
        foreach ($config as $key => $value) {
            config()->set("dynamic-throttle.dashboard.{$key}", $value);
        }

        app('router')->setRoutes(new RouteCollection);
        app()->register(DynamicThrottleServiceProvider::class, force: true);
    }

    /**
     * The app's environment, such as local or production.
     */
    public static function environment(string $environment): void
    {
        app()['env'] = $environment;
    }

    /**
     * Defines the gates the app would define. A null closure leaves that gate undefined.
     */
    public static function gates(?Closure $view, ?Closure $manage): void
    {
        if ($view instanceof Closure) {
            Gate::define('viewDynamicThrottle', $view);
        }

        if ($manage instanceof Closure) {
            Gate::define('manageDynamicThrottle', $manage);
        }
    }

    /**
     * A POST route in the dashboard's group that needs manageDynamicThrottle.
     */
    public static function writeRoute(): void
    {
        $path = trim((string) config('dynamic-throttle.dashboard.path'), '/');

        Route::middleware(DashboardRoutes::middleware('write'))
            ->post($path.'/'.self::WRITE_PATH, fn (): array => ['written' => true]);
    }
}
