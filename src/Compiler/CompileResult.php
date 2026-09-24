<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

/**
 * What one compile wrote (SPEC-006).
 */
final readonly class CompileResult
{
    public function __construct(
        public int $version,
        public int $rules,
        public int $skipped,
    ) {}
}
