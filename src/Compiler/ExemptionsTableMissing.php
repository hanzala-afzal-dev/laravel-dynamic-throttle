<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;

/**
 * The compiler found no exemptions table, as after an upgrade whose migration has
 * not run, so it compiled the rules alone (SPEC-009 AC-10). Reported on every compile.
 */
final class ExemptionsTableMissing extends RuntimeException {}
