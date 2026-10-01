<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Closure;
use Illuminate\Cache\ArrayStore;
use RuntimeException;

/**
 * An array cache store that counts the reads of each key, and fails every read
 * once $failing is set, as a cache store that is down does (SPEC-028). Each read
 * first runs $beforeRead, such as a clock that moves on while the read waits.
 */
final class CountingCacheStore extends ArrayStore
{
    public const string DOWN = 'The cache store is down.';

    public bool $failing = false;

    public ?Closure $beforeRead = null;

    /** @var array<string, int> how many times each key was read */
    private array $reads = [];

    public function get($key): mixed
    {
        $this->reads[$key] = $this->reads($key) + 1;
        ($this->beforeRead)?->__invoke();

        return $this->failing ? throw new RuntimeException(self::DOWN) : parent::get($key);
    }

    public function reads(string $key): int
    {
        return $this->reads[$key] ?? 0;
    }

    /**
     * Forgets the reads so far, such as the compiler's, so a test counts only the requests'.
     */
    public function resetReads(): void
    {
        $this->reads = [];
    }
}
