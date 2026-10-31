<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Links;

use Illuminate\Contracts\Config\Repository as Config;

/**
 * Whether the dashboard's links are on (SPEC-034 decision 2), and the address of one.
 *
 * @internal
 */
final class Links
{
    /** The longest a link works: a day (SPEC-034 decision 4). */
    public const int MAX_SECONDS = 86_400;

    /**
     * On only for true, or a text that says so, such as "true" from an env file:
     * anything else, a missing key included, keeps them off.
     */
    public static function enabled(Config $config): bool
    {
        return filter_var($config->get('dynamic-throttle.dashboard.links'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * The link: APP_URL, or dashboard.domain on APP_URL's scheme, then the dashboard's
     * path, /link, and the token after #, which browsers never send to a server. A
     * command knows no request, so APP_URL is all it has to go by.
     */
    public static function url(Config $config, string $path, string $token): string
    {
        $root = $config->get('app.url');
        $root = rtrim(is_string($root) && $root !== '' ? $root : 'http://localhost', '/');
        $domain = $config->get('dynamic-throttle.dashboard.domain');

        if (is_string($domain) && $domain !== '') {
            $scheme = parse_url($root, PHP_URL_SCHEME);
            $root = (is_string($scheme) ? $scheme : 'https').'://'.$domain;
        }

        return "{$root}/{$path}/link#{$token}";
    }
}
