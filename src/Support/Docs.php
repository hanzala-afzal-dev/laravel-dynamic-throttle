<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

/**
 * Links to the documentation site, for text users read: an app has no docs/ folder
 * of this package (issue #152).
 *
 * @internal
 */
final class Docs
{
    public const string SITE = 'https://hanzala-afzal-dev.github.io/laravel-dynamic-throttle/';

    /**
     * A page of the site, with a heading, such as "guide/dashboard#who-gets-in".
     */
    public static function url(string $page): string
    {
        return self::SITE.$page;
    }
}
