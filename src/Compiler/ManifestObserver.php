<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Recompiles the manifest after a rule, exemption or setting change commits, never
 * for one that rolls back (ADR-0007, SPEC-006 AC-8, SPEC-009 AC-7, SPEC-013 AC-4).
 * A failed compile is reported, not thrown: the change has already committed, and
 * the scheduled compile heals the manifest.
 */
final readonly class ManifestObserver implements ShouldHandleEventsAfterCommit
{
    public function __construct(
        private ManifestCompiler $compiler,
        // What a change through EmergencyControls reads back (SPEC-013).
        private LastCompile $last,
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
            $this->last->compiled($this->compiler->compile());
        } catch (ManifestCompileFailed $exception) {
            $this->last->failed($exception);
            report($exception);
        }
    }
}
