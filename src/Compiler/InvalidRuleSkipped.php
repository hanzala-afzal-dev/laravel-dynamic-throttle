<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;

/**
 * A rules row the compiler left out, reported so admins can fix it (SPEC-006 AC-4).
 */
final class InvalidRuleSkipped extends RuntimeException {}
