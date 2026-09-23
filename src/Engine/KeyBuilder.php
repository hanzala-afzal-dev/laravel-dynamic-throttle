<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine;

use LogicException;

/**
 * Builds every cache and Redis key the package uses (CLAUDE.md hard rule).
 */
final readonly class KeyBuilder
{
    public function __construct(
        public string $prefix = 'dt:',
    ) {}

    /**
     * The key for one window of one counter (ADR-0006).
     */
    public function counter(CounterKey $key, int $windowSeconds, int $windowIndex): string
    {
        throw new LogicException('Not implemented yet (SPEC-002).');
    }
}
