<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A dangerous change sent without "confirm": true (SPEC-018). A stub until its implementation.
 *
 * @internal
 */
final class ConfirmationRequired extends HttpException {}
