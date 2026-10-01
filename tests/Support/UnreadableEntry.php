<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use RuntimeException;

/**
 * An APCu entry that throws when APCu reads it back, as an object whose class
 * changed since it was stored can (SPEC-028 AC-9).
 */
final class UnreadableEntry
{
    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function __unserialize(array $data): void
    {
        throw new RuntimeException('This entry cannot be read.');
    }
}
