<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Where the dashboard is served (SPEC-018). The request path reads it to leave the
 * dashboard alone by its path too, since global middleware runs before routing and
 * cannot see the routes' names (ADR-0004 point 5). It lives outside Http\Dashboard,
 * which the request path never uses.
 *
 * @internal
 */
final class DashboardPath
{
    /**
     * dashboard.path without its slashes, or null when the dashboard is off, or its path
     * is empty, since it would take the site's root.
     */
    public static function of(Config $config): ?string
    {
        return $config->get('dynamic-throttle.dashboard.enabled') === false ? null : self::configured($config);
    }

    /**
     * dashboard.path without its slashes, or null when nothing is left, whether the
     * dashboard is on or off.
     */
    public static function configured(Config $config): ?string
    {
        $path = $config->get('dynamic-throttle.dashboard.path');
        $path = is_string($path) ? trim($path, '/') : '';

        return $path === '' ? null : $path;
    }

    /**
     * Whether a path, as Request::decodedPath() gives it, is the dashboard's or under it:
     * "dynamic-throttle" and "dynamic-throttle/api/me", never "dynamic-throttle-other".
     */
    public static function covers(Config $config, string $path): bool
    {
        $dashboard = self::of($config);

        return $dashboard !== null && ($path === $dashboard || str_starts_with($path, $dashboard.'/'));
    }
}
