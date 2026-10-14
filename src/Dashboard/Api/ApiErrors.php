<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\ConfirmationRequired;
use Afzal\DynamicThrottle\Support\TableMissing;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/**
 * The API's own errors, as JSON whatever the request accepts, and never a
 * redirect (SPEC-017): a 422 with the fields' messages, a 409 that says what a
 * dangerous change does (SPEC-018), and a 503 when a table is missing. Laravel's
 * handler would redirect a request that does not ask for JSON back to the page
 * it came from.
 *
 * @internal
 */
final class ApiErrors
{
    /**
     * @param  Closure(): JsonResponse  $action
     */
    public static function json(Closure $action): JsonResponse
    {
        try {
            return $action();
        } catch (ValidationException $exception) {
            return new JsonResponse(['message' => $exception->getMessage(), 'errors' => $exception->errors()], $exception->status);
        } catch (ConfirmationRequired $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (TableMissing $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 503);
        }
    }
}
