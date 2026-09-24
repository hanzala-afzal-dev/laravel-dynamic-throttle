<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use UnexpectedValueException;

/**
 * The cache holds something that is not a manifest in the current format (SPEC-006 AC-16).
 */
final class InvalidManifest extends UnexpectedValueException {}
