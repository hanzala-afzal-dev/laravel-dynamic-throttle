<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Changes;

use Illuminate\Http\Request;
use LogicException;

/**
 * Stub for SPEC-017's red tests.
 *
 * @internal
 */
final readonly class Actor
{
    public function __construct(
        public string $name,
        public ?string $ip = null,
        public ?string $userAgent = null,
    ) {}

    public static function cli(): self
    {
        throw new LogicException('Not built yet (SPEC-017).');
    }

    public static function of(Request $request): self
    {
        throw new LogicException('Not built yet (SPEC-017).');
    }
}
