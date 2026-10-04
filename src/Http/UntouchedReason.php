<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

/**
 * Why a request goes through untouched (SPEC-014), in the order the steps run.
 *
 * @internal
 */
enum UntouchedReason: string
{
    /** No route runs dynamic.throttle, and it is not global middleware. Only explain sees it. */
    case NotAttached = 'not_attached';

    /** dynamic-throttle.enabled is off. */
    case Disabled = 'disabled';

    /** The package's own routes, such as the dashboard's (ADR-0004 point 5). */
    case OwnRoute = 'own_route';

    /** A path in except (SPEC-007). */
    case Except = 'except';

    /** Bypass is on (SPEC-013). */
    case Bypass = 'bypass';

    /** No rule matches the request. */
    case NoRule = 'no_rule';
}
