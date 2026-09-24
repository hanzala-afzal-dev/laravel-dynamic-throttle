<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Compiler;

use RuntimeException;
use Throwable;

/**
 * A compile that wrote nothing; the stored manifest is unchanged (SPEC-006 AC-6, AC-7).
 */
final class ManifestCompileFailed extends RuntimeException
{
    public static function because(Throwable $previous): self
    {
        return $previous instanceof self
            ? $previous
            : new self('The manifest could not be compiled: '.$previous->getMessage(), previous: $previous);
    }
}
