<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use RuntimeException;

/**
 * An object whose copies that APCu reads back throw when they are released, as
 * another app's object can (SPEC-028 AC-9). The instance a test creates never
 * throws.
 */
final class ThrowsWhenReleased
{
    private bool $copy = false;

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
        $this->copy = true;
    }

    public function __destruct()
    {
        if ($this->copy) {
            throw new RuntimeException('A copy APCu read back was released.');
        }
    }
}
