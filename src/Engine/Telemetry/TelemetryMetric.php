<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

/**
 * The numbers telemetry keeps per rule and minute (SPEC-015), named as the hourly
 * table's columns.
 *
 * @internal
 */
enum TelemetryMetric: string
{
    /** The requests the rule checked. */
    case Hits = 'hits';

    /** The requests an enforcing rule rejected. */
    case Blocks = 'blocks';

    /** The requests a dry-run rule would have rejected. */
    case WouldBlocks = 'would_blocks';
}
