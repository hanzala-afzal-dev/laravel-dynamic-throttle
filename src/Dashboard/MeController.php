<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Illuminate\Http\JsonResponse;

/**
 * GET {path}/api/me: what the user may do, so the dashboard's script shows only
 * that (SPEC-018).
 *
 * @internal
 */
final readonly class MeController
{
    public function __invoke(Gates $gates): JsonResponse
    {
        return new JsonResponse(['can' => $gates->abilities()]);
    }
}
