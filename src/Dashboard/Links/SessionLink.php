<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Links;

use Afzal\DynamicThrottle\Models\DashboardLink;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Throwable;

/**
 * The link a request's session was started with, while it still works (SPEC-034
 * decisions 9 and 10): links are on, and the link was used and has not ended. It is
 * read from the table on each of the dashboard's requests, once per request, so a
 * link that expires or is revoked stops working at the next one. The dashboard is
 * not the request path, so it may read the database (ADR-0007).
 *
 * @internal
 */
final readonly class SessionLink
{
    /** The session key, which holds the link's id only, never its token (decision 9). */
    public const string KEY = 'dynamic-throttle.link';

    /** Where the request keeps the link it read, so the gates and the page read it once. */
    private const string ATTRIBUTE = 'dynamic-throttle.session-link';

    public function __construct(
        private Config $config,
    ) {}

    public function of(Request $request): ?DashboardLink
    {
        if (! Links::enabled($this->config) || ! $request->hasSession()) {
            return null;
        }

        if ($request->attributes->has(self::ATTRIBUTE)) {
            $link = $request->attributes->get(self::ATTRIBUTE);

            return $link instanceof DashboardLink ? $link : null;
        }

        $link = $this->read($request);
        $request->attributes->set(self::ATTRIBUTE, $link ?? false);

        return $link;
    }

    /**
     * What the link lets its holder do: view, and manage too with --manage.
     *
     * @return array{view: bool, manage: bool}
     */
    public function abilities(Request $request): array
    {
        $link = $this->of($request);

        return ['view' => $link instanceof DashboardLink, 'manage' => $link instanceof DashboardLink && $link->can_manage];
    }

    private function read(Request $request): ?DashboardLink
    {
        $id = $request->session()->get(self::KEY);

        if (! is_int($id)) {
            return null;
        }

        try {
            $link = DashboardLink::query()->find($id);
        } catch (Throwable) {
            // A table that is missing or down lets nobody in through a link: the gates still decide.
            return null;
        }

        if ($link instanceof DashboardLink && $link->used_at !== null && ! $link->ended()) {
            return $link;
        }

        // Ended: forget it, so the next requests read nothing.
        $request->session()->forget(self::KEY);

        return null;
    }
}
