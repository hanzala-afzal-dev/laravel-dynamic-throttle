<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Illuminate\Http\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * GET {path}/assets/{file} (SPEC-020): the dashboard's build, with no gate and no
 * session, so browsers and proxies cache it for a year; a new build has new names
 * (decision 2). Only a file the manifest lists is served.
 *
 * @internal
 */
final readonly class AssetController
{
    public function __construct(
        private ViteManifest $manifest,
    ) {}

    public function __invoke(string $file): Response
    {
        $path = $this->manifest->path($file);

        if (! $this->manifest->serves($file) || ! is_file($path)) {
            throw new NotFoundHttpException;
        }

        // Small files: the first page's JavaScript stays under 300 KB gzipped (ADR-0028 point 5).
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $this->type($file),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function type(string $file): string
    {
        return match (pathinfo($file, PATHINFO_EXTENSION)) {
            'js' => 'application/javascript; charset=utf-8',
            'css' => 'text/css; charset=utf-8',
            'svg' => 'image/svg+xml',
            'woff2' => 'font/woff2',
            'png' => 'image/png',
            default => 'application/octet-stream',
        };
    }
}
