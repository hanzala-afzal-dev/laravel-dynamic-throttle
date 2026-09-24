<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest\Contracts;

/**
 * Rebuilds a missing manifest. The read path calls it after the response, so
 * it never depends on the compiler or the database itself (SPEC-006, ADR-0007).
 */
interface ManifestRebuilder
{
    public function rebuild(): void;
}
