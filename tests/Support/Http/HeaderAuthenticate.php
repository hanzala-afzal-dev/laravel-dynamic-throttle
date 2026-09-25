<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support\Http;

use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Authentication that Laravel cannot see, like a hand-written API key check: it
 * logs in the user the X-User header names, and does not implement
 * AuthenticatesRequests, so Laravel's priority list does not know it (ADR-0020
 * point 4).
 */
final class HeaderAuthenticate
{
    public const string ALIAS = 'header.auth';

    public function handle(Request $request, Closure $next): mixed
    {
        $id = $request->headers->get('X-User');

        if ($id !== null) {
            Auth::setUser(new TestUser(['id' => (int) $id]));
        }

        return $next($request);
    }
}
