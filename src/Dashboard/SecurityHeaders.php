<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every response of the dashboard, a 403 and a 429 too: never cached, never read as
 * another type than it says (SPEC-018), and never framed. The pages' Content
 * Security Policy comes with them (SPEC-020).
 *
 * @internal
 */
final class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, private');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        // As the pages' frame-ancestors 'none', for an app whose own policy replaces the package's (SPEC-020 AC-16).
        $response->headers->set('X-Frame-Options', 'DENY');

        return $response;
    }
}
