<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard\Api;

use Afzal\DynamicThrottle\Support\Health\HealthChecks;
use Illuminate\Http\JsonResponse;

/**
 * GET api/health (SPEC-017): the report dynamic-throttle:health --json prints, with
 * 200 whatever its status, so the Overview can poll it and show a failing check
 * (decision 13).
 *
 * @internal
 */
final readonly class HealthController
{
    public function __invoke(HealthChecks $checks): JsonResponse
    {
        return new JsonResponse($checks->run(), 200, [], JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
