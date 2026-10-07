<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support\Http;

use Closure;
use Illuminate\Http\Request;

/**
 * Trusts a proxy partway through the request, as Laravel's TrustProxies does when
 * the app runs it after dynamic.throttle, such as a route middleware after the
 * global one: the rules counted the proxy's IP, and later reads of the client IP
 * give the client behind it (SPEC-016's review). Tests reset the trusted proxies
 * afterwards: they are static on Symfony's Request.
 */
final class TrustProxyLater
{
    public const string PROXY = '10.0.0.1';

    public function handle(Request $request, Closure $next): mixed
    {
        Request::setTrustedProxies([self::PROXY], Request::HEADER_X_FORWARDED_FOR);

        return $next($request);
    }
}
