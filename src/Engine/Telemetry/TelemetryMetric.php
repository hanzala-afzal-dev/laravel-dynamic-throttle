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

    /** The requests a user rule counted without a user (SPEC-016). */
    case Guests = 'guests';

    /** The requests a rule let pass because they had no identity for it (SPEC-016, ADR-0022). */
    case Skipped = 'skipped';

    /** The requests a rule was ignored for, over the cap (SPEC-016, ADR-0025). */
    case Ignored = 'ignored';
}
