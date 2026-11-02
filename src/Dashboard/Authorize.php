<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Afzal\DynamicThrottle\Dashboard\Links\SessionLink;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The dashboard's routes need a gate, or a link (SPEC-034): a read route either, a write route, and any
 * request but GET, HEAD and OPTIONS, manageDynamicThrottle. An inspect route, POST
 * api/explain, takes a POST with either gate, since it writes nothing (SPEC-017). A denied request gets a 403, as JSON when it asks for
 * JSON, and never a redirect to a login page the app may not have (decision 8).
 *
 * @internal
 */
final readonly class Authorize
{
    public function __construct(
        private Gates $gates,
        private SessionLink $links,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  string  $ability  read, inspect or write, as a route's middleware names it
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $needs = match ($ability) {
            'read', 'inspect' => 'view',
            'write' => 'manage',
            default => throw new LogicException("Unknown dashboard ability \"{$ability}\": use read, inspect or write."),
        };

        // A change needs manage whatever the route asks, so a route marked read by mistake cannot let viewers write.
        // The one exception is inspect's POST, which writes nothing and keeps its input out of URLs (SPEC-017 decision 2).
        if (! $request->isMethodSafe() && ($ability !== 'inspect' || ! $request->isMethod('POST'))) {
            $needs = 'manage';
        }

        if ($this->gates->abilities()[$needs]) {
            return $next($request);
        }

        // A link adds to the gates, and names itself as the actor of what only it allowed (SPEC-034 decisions 10 and 11).
        $link = $this->links->of($request);

        if (! $link instanceof DashboardLink || ! $this->links->abilities($request)[$needs]) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        $request->attributes->set(Actor::ALLOWED_BY_LINK, $link->actor());

        return $next($request);
    }
}
