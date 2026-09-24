<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Recompiles the manifest after a rule change commits, never for one that rolls
 * back (ADR-0007, SPEC-006 AC-8). A failed compile is reported, not thrown: the
 * change has already committed, and the scheduled compile heals the manifest.
 */
final readonly class RuleObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private ManifestCompiler $compiler,
    ) {}

    public function saved(): void
    {
        $this->compile();
    }

    public function deleted(): void
    {
        $this->compile();
    }

    private function compile(): void
    {
        try {
            $this->compiler->compile();
        } catch (ManifestCompileFailed $exception) {
            report($exception);
        }
    }
}
