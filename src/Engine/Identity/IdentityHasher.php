<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

use LogicException;

final readonly class IdentityHasher
{
    public function __construct(
        private ?string $appKey,
    ) {}

    public function hash(string $identity): string
    {
        throw new LogicException("Not implemented yet (SPEC-008): {$this->appKey}");
    }
}
