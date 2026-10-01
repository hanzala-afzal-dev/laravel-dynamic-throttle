<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Manifest\Contracts\ManifestRebuilder;

/**
 * Counts the rebuilds the read path schedules (SPEC-006, SPEC-028).
 */
final class RecordingRebuilder implements ManifestRebuilder
{
    public int $calls = 0;

    public function rebuild(): void
    {
        $this->calls++;
    }
}
