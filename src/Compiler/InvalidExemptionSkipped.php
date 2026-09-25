<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;

/**
 * An exemptions row the compiler left out, reported so admins can fix it (SPEC-009 AC-10).
 */
final class InvalidExemptionSkipped extends RuntimeException {}
