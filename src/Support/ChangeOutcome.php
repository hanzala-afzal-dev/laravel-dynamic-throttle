<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Compiler\CompileResult;
use Afzal\DynamicThrottle\Compiler\ManifestCompileFailed;

/**
 * What a change through EmergencyControls did (SPEC-013): nothing, or a change
 * that is saved, with the compile that followed it, the reason that compile
 * failed, or neither while a surrounding transaction has yet to commit.
 *
 * @internal
 */
final readonly class ChangeOutcome
{
    private function __construct(
        public bool $changed,
        public ?CompileResult $compiled = null,
        public ?ManifestCompileFailed $compileFailed = null,
    ) {}

    public static function unchanged(): self
    {
        return new self(false);
    }

    public static function compiled(CompileResult $result): self
    {
        return new self(true, compiled: $result);
    }

    public static function compileFailed(ManifestCompileFailed $failure): self
    {
        return new self(true, compileFailed: $failure);
    }

    /**
     * Saved in a surrounding transaction: the manifest compiles if it commits.
     */
    public static function pending(): self
    {
        return new self(true);
    }
}
