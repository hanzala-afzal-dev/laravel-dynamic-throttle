<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

/**
 * An attempt the evaluator plans for a request, before any store call
 * (ADR-0026, SPEC-027): which counter, against which limit and window, and
 * whether a rejection stops the request.
 */
final readonly class PlannedAttempt
{
    public function __construct(
        public CounterKey $key,
        public int $maxAttempts,
        public int $windowSeconds,
        /** An enforcing rule's rejection stops the batch. A dry-run rule's never does (ADR-0012). */
        public bool $enforces,
    ) {}
}
