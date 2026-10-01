<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest\Contracts;

use Afzal\DynamicThrottle\Manifest\ManifestSnapshot;

/**
 * L1 (ADR-0007, SPEC-028): the last manifest a server read from the cache
 * store, which the server's processes share. Neither method throws: a cache
 * that fails keeps nothing, and the request reads the store.
 *
 * @internal
 */
interface LocalManifestCache
{
    /**
     * The snapshot last stored, or null when there is none, or none that can be read.
     */
    public function fetch(): ?ManifestSnapshot;

    public function store(ManifestSnapshot $snapshot): void;
}
