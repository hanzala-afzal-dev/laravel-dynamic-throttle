<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Exceptions;

use RuntimeException;

/**
 * The counter store could not be used. The failure policy decides what
 * happens to the request (ADR-0010).
 */
final class StorageUnavailableException extends RuntimeException {}
