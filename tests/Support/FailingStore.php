<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterKey;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;

/**
 * A counter store that fails as a store that is down does (ADR-0010, ADR-0021).
 */
final readonly class FailingStore implements LimiterStore
{
    public const string DOWN = 'The store is down.';

    public const string RELEASE_FAILED = 'The store could not release the count.';

    private function __construct(
        private ?LimiterStore $inner,
    ) {}

    /**
     * Every attempt fails.
     */
    public static function down(): self
    {
        return new self(null);
    }

    /**
     * Attempts count through $inner, and every release fails.
     */
    public static function failingReleases(LimiterStore $inner): self
    {
        return new self($inner);
    }

    public function attempt(CounterKey $key, int $maxAttempts, int $windowSeconds): Attempt
    {
        return $this->inner instanceof LimiterStore
            ? $this->inner->attempt($key, $maxAttempts, $windowSeconds)
            : throw StorageUnavailableException::because(self::DOWN);
    }

    public function release(Attempt $attempt): void
    {
        throw StorageUnavailableException::because(self::RELEASE_FAILED);
    }
}
