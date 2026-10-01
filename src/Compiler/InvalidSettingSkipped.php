<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;

/**
 * A settings row the compiler left out, reported so admins can fix it (SPEC-013 AC-3).
 */
final class InvalidSettingSkipped extends RuntimeException {}
