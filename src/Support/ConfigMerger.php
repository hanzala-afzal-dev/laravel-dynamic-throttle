<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use LogicException;

/**
 * @internal
 */
final class ConfigMerger
{
    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $app): array
    {
        throw new LogicException('Not implemented yet (SPEC-005).');
    }
}
