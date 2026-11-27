<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Dashboard\Validation\ConfirmationRequired;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Support\TableMissing;
use Closure;
use Illuminate\Container\Container;
use Illuminate\Contracts\Database\LostConnectionDetector as LostConnectionDetectorContract;
use Illuminate\Database\LostConnectionDetector;
use Illuminate\Database\LostConnectionException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use PDOException;
use Throwable;

/**
 * The API's own errors, as JSON whatever the request accepts, and never a
 * redirect (SPEC-017): a 422 with the fields' messages, a 409 that says what a
 * dangerous change does (SPEC-018), and a 503 when a table is missing, the
 * counter store is down, or the database cannot be reached (AC-28). Laravel's
 * handler would redirect a request that does not ask for JSON back to the page
 * it came from.
 *
 * @internal
 */
final class ApiErrors
{
    /**
     * The 503 when the database cannot be reached (AC-28, issue #209). Plain words only:
     * the driver's error names the host and the SQL, which a viewer must not see.
     */
    public const string DATABASE_DOWN = 'The database cannot be reached, so the dashboard can neither show nor change what it keeps there. Requests never read the database: they apply the rules compiled into the cache.';

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
        } catch (TableMissing|StorageUnavailableException $exception) {
            return new JsonResponse(['message' => $exception->getMessage()], 503);
        } catch (QueryException|PDOException|LostConnectionException $exception) {
            // Any other database error stays a 500, so a mistake never looks like an outage.
            if (! self::unreachable($exception)) {
                throw $exception;
            }

            // Reported as the 500 was, so the outage still reaches the app's error tracker.
            report($exception);

            return new JsonResponse(['message' => self::DATABASE_DOWN], 503);
        }
    }

    /**
     * A lost or refused connection, as Laravel tells one for its own reconnects, with the
     * app's own detector when it binds one. Or an SQLSTATE of class 08, the SQL standard's
     * connection exceptions: PostgreSQL refuses with one, in words that Laravel 12's list
     * does not know.
     */
    private static function unreachable(Throwable $exception): bool
    {
        $container = Container::getInstance();
        $detector = $container->bound(LostConnectionDetectorContract::class)
            ? $container->make(LostConnectionDetectorContract::class)
            : new LostConnectionDetector;

        return $detector->causedByLostConnection($exception) || str_starts_with($exception->getMessage(), 'SQLSTATE[08');
    }
}
