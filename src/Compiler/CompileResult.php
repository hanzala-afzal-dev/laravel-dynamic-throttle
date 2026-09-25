<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

/**
 * What one compile wrote (SPEC-006, SPEC-009).
 */
final readonly class CompileResult
{
    /**
     * @param  int  $rules  the rules compiled
     * @param  int  $skipped  the invalid rule rows left out, and reported
     * @param  int  $exemptions  the exemptions compiled
     * @param  int  $skippedExemptions  the invalid exemption rows left out, and reported
     */
    public function __construct(
        public int $version,
        public int $rules,
        public int $skipped,
        public int $exemptions,
        public int $skippedExemptions,
    ) {}
}
