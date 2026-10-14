<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Closure;
use Illuminate\Http\Request;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The dashboard's routes need a gate: a read route either, a write route, and any
 * request but GET, HEAD and OPTIONS, manageDynamicThrottle. A denied request gets a 403, as JSON when it asks for
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
     * @param  string  $ability  read or write, as a route's middleware names it
     */
    public function handle(Request $request, Closure $next, string $ability = 'read'): Response
    {
        $needs = match ($ability) {
            'read' => 'view',
            'write' => 'manage',
            default => throw new LogicException("Unknown dashboard ability \"{$ability}\": use read or write."),
        };

        // A change needs manage whatever the route asks, so a route marked read by mistake cannot let viewers write.
        if (! $request->isMethodSafe()) {
            $needs = 'manage';
        }

        if (! $this->gates->abilities()[$needs]) {
            throw new AccessDeniedHttpException('This action is unauthorized.');
        }

        return $next($request);
    }
}
