<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Compiler\ManifestCompiler;
use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;
use Illuminate\Contracts\Container\Container;

/**
 * Builds the compiler only when a missing manifest is rebuilt, after the
 * response (SPEC-006). Every limited request builds the manifest repository,
 * and almost none of them rebuilds (the Phase 2 exit review).
 */
final readonly class LazyManifestRebuilder implements ManifestRebuilder
{
    public function __construct(
        private Container $container,
    ) {}

    public function rebuild(): void
    {
        $this->container->make(ManifestCompiler::class)->rebuild();
    }
}
