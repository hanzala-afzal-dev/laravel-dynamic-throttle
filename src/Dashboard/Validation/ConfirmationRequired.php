<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Validation;

use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * A dangerous change sent without "confirm": true (SPEC-018 decision 3): a 409 whose
 * message says what the change does, which the dashboard's dialog shows.
 *
 * @internal
 */
final class ConfirmationRequired extends HttpException
{
    public function __construct(string $consequence)
    {
        parent::__construct(409, $consequence);
    }
}
