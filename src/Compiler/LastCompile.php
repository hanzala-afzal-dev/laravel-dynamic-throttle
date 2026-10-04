<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

/**
 * What the ManifestObserver's last compile gave, so EmergencyControls can tell its
 * caller whether servers will see a change (SPEC-013). The observer compiles once
 * per change, after the commit (ADR-0007). Scoped, so each Octane request and each
 * queued job starts empty.
 *
 * @internal
 */
final class LastCompile
{
    private ?CompileResult $result = null;

    private ?ManifestCompileFailed $failure = null;

    public function forget(): void
    {
        $this->result = null;
        $this->failure = null;
    }

    public function compiled(CompileResult $result): void
    {
        $this->result = $result;
        $this->failure = null;
    }

    public function failed(ManifestCompileFailed $failure): void
    {
        $this->result = null;
        $this->failure = $failure;
    }

    public function result(): ?CompileResult
    {
        return $this->result;
    }

    public function failure(): ?ManifestCompileFailed
    {
        return $this->failure;
    }
}
