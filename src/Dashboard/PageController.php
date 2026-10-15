<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Afzal\DynamicThrottle\Http\DashboardPath;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\View\Factory as ViewFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page shell (SPEC-020): the same small HTML page for each of the dashboard's
 * pages, so the browser's own routes reload. It loads the build, and holds the base
 * path, the CSRF token and the nonce; React draws the rest (ADR-0028 point 4).
 *
 * @internal
 */
final readonly class PageController
{
    /**
     * The pages' paths under the dashboard's, and only those: a catch-all would take
     * the app's own routes there, such as {locale}/login (SPEC-018 AC-20).
     */
    public const string PAGES = 'rules(/new|/[0-9]+(/edit)?)?|exemptions|activity|audit|explain|settings';

    /** The shell, which ships with the package. */
    private const string VIEW = __DIR__.'/../../resources/views/dashboard.blade.php';

    public function __construct(
        private ViteManifest $manifest,
        private Config $config,
        private ViewFactory $views,
    ) {}

    public function __invoke(Request $request): Response
    {
        $entry = $this->manifest->entry();

        if ($entry === null) {
            return new Response('The dashboard is not built: run npm ci and npm run build.', 503, ['Content-Type' => 'text/plain; charset=utf-8']);
        }

        // One leading slash: a request path that starts with two would make the base another host's (SPEC-020 AC-15).
        $base = '/'.ltrim($request->getBaseUrl().'/'.DashboardPath::of($this->config), '/');
        $nonce = $request->attributes->get(ContentSecurityPolicy::NONCE);

        return new Response($this->views->file(self::VIEW, [
            'base' => $base,
            'assets' => $base.'/assets/',
            'entry' => $entry,
            // A dashboard.middleware without the web group has no session, and so no CSRF token to give (SPEC-020 AC-14).
            'csrfToken' => $request->hasSession() ? $request->session()->token() : '',
            'nonce' => is_string($nonce) ? $nonce : '',
        ])->render(), 200, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
