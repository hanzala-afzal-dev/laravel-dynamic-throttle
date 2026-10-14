<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The dashboard's routes need a gate: a read route either, a write route
 * manageDynamicThrottle. A denied request gets a 403, as JSON when it asks for
 * JSON, and never a redirect to a login page the app may not have (decision 8).
 *
 * @internal
 */
final readonly class Authorize
{
    public function __construct(
        private Gates $gates,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     * @param  'read'|'write'  $ability
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $can = $this->gates->abilities();

        if (! ($ability === 'write' ? $can['manage'] : $can['view'])) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        return $next($request);
    }
}
