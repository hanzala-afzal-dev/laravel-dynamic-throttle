<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Algorithm;

/**
 * The decision for one request under the sliding-window counter (SPEC-001).
 */
final readonly class Evaluation
{
    public function __construct(
        /** Whether this request may proceed. */
        public bool $allowed,
        /** Requests left before the limit, 0 when rejected. */
        public int $remaining,
        /** Whole seconds to wait before retrying: at least 1 when rejected, 0 when allowed. */
        public int $retryAfter,
        /** Estimated usage before this request: previous × (window − elapsed) / window + current. */
        public float $estimate,
    ) {}
}
