<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use RuntimeException;

/**
 * The manifest is missing from the cache, or the cache cannot be read (SPEC-006).
 * Reported, never thrown to the request.
 */
final class ManifestUnavailable extends RuntimeException {}
