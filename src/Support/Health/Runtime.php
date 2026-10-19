<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Support\ApcuManifestCache;

/**
 * The PHP that runs a health check (issue #100): its SAPI, whether APCu works
 * there, and whether Octane serves it. dynamic-throttle:health sees the command
 * line's PHP, and the dashboard's api/health the web server's.
 *
 * @internal
 */
final readonly class Runtime
{
    public function __construct(
        public string $sapi,
        public bool $apcu,
        public bool $octane,
    ) {}

    /**
     * Octane marks its workers with LARAVEL_OCTANE, as Laravel itself reads it. Its
     * workers can run under the command line's SAPI, as Swoole and RoadRunner do.
     */
    public static function current(): self
    {
        return new self(PHP_SAPI, ApcuManifestCache::available(), isset($_SERVER['LARAVEL_OCTANE']));
    }
}
