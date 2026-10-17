<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use LogicException;

/**
 * A stub for SPEC-030's red tests: one entry of the live feed.
 *
 * @internal
 */
final readonly class FeedEntry
{
    public function __construct(
        public int $time,
        public string $ruleId,
        public TelemetryOutcome $outcome,
        public string $method,
        public ?string $route,
        public ?string $ip,
        public ?string $user,
        public string $identity,
    ) {}

    public function toJson(): string
    {
        throw new LogicException('Not implemented yet (SPEC-030).');
    }

    public static function fromJson(string $json): ?self
    {
        throw new LogicException('Not implemented yet (SPEC-030).');
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new LogicException('Not implemented yet (SPEC-030).');
    }
}
