<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Links;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The link's routes answer 404 while links are off (SPEC-034 decision 2), on each
 * request: cached routes keep them after the config turns links off, until
 * route:cache runs again (the review).
 *
 * @internal
 */
final readonly class LinksEnabled
{
    public function __construct(
        private Config $config,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! Links::enabled($this->config)) {
            throw new NotFoundHttpException;
        }

        return $next($request);
    }
}
