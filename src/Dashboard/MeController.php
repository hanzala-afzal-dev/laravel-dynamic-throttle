<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Afzal\DynamicThrottle\Dashboard\Links\SessionLink;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET {path}/api/me: what the user may do, so the dashboard's script shows only
 * that (SPEC-018), the app's environment (SPEC-020), and the link the session was
 * started with, if any, which the header names (SPEC-034).
 *
 * @internal
 */
final readonly class MeController
{
    public function __invoke(Request $request, Gates $gates, SessionLink $links, Application $app): JsonResponse
    {
        $can = $gates->abilities();
        $link = $links->of($request);
        $byLink = $links->abilities($request);

        return new JsonResponse([
            // A link adds to the gates (SPEC-034 decision 10).
            'can' => ['view' => $can['view'] || $byLink['view'], 'manage' => $can['manage'] || $byLink['manage']],
            // The environment too, which the header shows, so an admin sees at once whether this is production (SPEC-020).
            'environment' => $app->environment(),
            'link' => $link instanceof DashboardLink
                ? ['name' => $link->name, 'manage' => $link->can_manage, 'expires_at' => $link->expires_at->toIso8601String()]
                : null,
        ]);
    }
}
