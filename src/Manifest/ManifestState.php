<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

/**
 * Where the manifest a request sees came from (SPEC-006).
 */
enum ManifestState: string
{
    /** Read from the cache store, or from memory within refresh_seconds of that read. */
    case Fresh = 'fresh';

    /** The last manifest this process read: the store has none, or cannot be read. */
    case Stale = 'stale';

    /** No manifest anywhere, so no rule applies (ADR-0010 point 3). */
    case Missing = 'missing';
}
