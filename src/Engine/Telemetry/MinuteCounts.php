<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

/**
 * One rule's numbers for one minute (SPEC-015).
 *
 * @internal
 */
final readonly class MinuteCounts
{
    public function __construct(
        /** The requests the rule checked. */
        public int $hits,
        /** The requests an enforcing rule rejected. */
        public int $blocks,
        /** The requests a dry-run rule would have rejected. */
        public int $wouldBlocks,
    ) {}
}
