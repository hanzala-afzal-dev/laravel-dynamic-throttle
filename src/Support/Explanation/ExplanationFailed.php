<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use RuntimeException;

/**
 * Why there is no explanation: the manifest, or the user or the guard asked for.
 *
 * @internal
 */
final class ExplanationFailed extends RuntimeException {}
