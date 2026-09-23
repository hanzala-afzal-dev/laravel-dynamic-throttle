<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

/**
 * Identifies one counter: a rule and the hash of a client identity (ADR-0009).
 */
final readonly class CounterKey
{
    public function __construct(
        public string $ruleId,
        public string $identityHash,
    ) {}
}
