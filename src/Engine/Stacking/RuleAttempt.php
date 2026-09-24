<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * One rule's attempt for a request (SPEC-007).
 */
final readonly class RuleAttempt
{
    public function __construct(
        public CompiledRule $rule,
        public Attempt $attempt,
        /** True once compensation uncounted it (ADR-0008). */
        public bool $released = false,
    ) {}
}
