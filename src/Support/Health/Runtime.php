<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

/**
 * The PHP that runs a health check (issue #100): its SAPI, whether APCu works
 * there, and whether Octane serves it. A stub, for the red tests.
 *
 * @internal
 */
final readonly class Runtime
{
    public function __construct(
        public string $sapi,
        public bool $apcu,
        public bool $octane,
    ) {}

    public static function current(): self
    {
        return new self('', false, false);
    }
}
