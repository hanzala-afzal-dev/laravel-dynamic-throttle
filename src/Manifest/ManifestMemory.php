<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

/**
 * L0 (ADR-0007): what this PHP process remembers between requests. Static on
 * purpose, so Octane and queue workers keep it across requests. PHP-FPM starts
 * every request empty. ManifestRepository::flushState() clears it.
 *
 * @internal
 */
final class ManifestMemory
{
    public static ?Manifest $manifest = null;

    /** When $manifest was last read from the store, or found missing, in microseconds. */
    public static int $readAt = 0;

    /** When an unreadable manifest was last reported, in microseconds. */
    public static ?int $reportedAt = null;
}
