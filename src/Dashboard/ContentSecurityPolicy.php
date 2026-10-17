<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page shell's strict Content Security Policy (ADR-0018 point 4, ADR-0028): its
 * own scripts and styles only, nothing inline, and a nonce, new on every request, for
 * the <style> elements libraries insert at run time (SPEC-020 decision 3). A policy
 * the app already gave the response stays.
 *
 * @internal
 */
final class ContentSecurityPolicy
{
    /** The request attribute that holds the nonce, for the shell's <meta> tag. */
    public const string NONCE = 'dynamic-throttle.nonce';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(16));
        $request->attributes->set(self::NONCE, $nonce);

        $response = $next($request);

        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', self::policy($nonce));
        }

        if (! $response->headers->has('Referrer-Policy')) {
            $response->headers->set('Referrer-Policy', 'same-origin');
        }

        return $response;
    }

    public static function policy(string $nonce): string
    {
        return "default-src 'none'; script-src 'self'; style-src 'self' 'nonce-{$nonce}'; img-src 'self' data:; font-src 'self'; "
            ."connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'";
    }
}
