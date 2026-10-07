<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use LogicException;

/**
 * SPEC-015: not built yet.
 *
 * @internal
 */
final readonly class MetricsFlusher
{
    /**
     * @return list<int> the minutes moved, in Unix minutes
     */
    public function flush(): array
    {
        throw new LogicException('SPEC-015: not built yet.');
    }
}
