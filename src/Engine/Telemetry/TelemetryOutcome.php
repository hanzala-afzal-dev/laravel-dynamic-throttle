<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

/**
 * What one rule did with one request, for telemetry (SPEC-015 decision 2). Each is a
 * hit: the rule checked the request.
 *
 * @internal
 */
enum TelemetryOutcome: string
{
    /** The rule counted the request and let it through, even if a later rule rejected it. */
    case Counted = 'counted';

    /** An enforcing rule rejected the request with a 429. */
    case Blocked = 'blocked';

    /** A dry-run rule would have rejected it (ADR-0012). */
    case WouldBlock = 'would_block';
}
