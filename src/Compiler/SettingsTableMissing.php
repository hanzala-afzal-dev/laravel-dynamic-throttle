<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;

/**
 * The app has not run the migration that adds the settings, so the manifest has
 * none (SPEC-013). Limits keep working.
 */
final class SettingsTableMissing extends RuntimeException {}
