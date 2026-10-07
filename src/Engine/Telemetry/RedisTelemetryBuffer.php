<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Illuminate\Contracts\Redis\Factory as RedisFactory;
use LogicException;

/**
 * SPEC-015: not built yet.
 *
 * @internal
 */
final readonly class RedisTelemetryBuffer implements TelemetryBuffer
{
    public function __construct(
        private RedisFactory $redis,
        public string $connection,
        private KeyBuilder $keys,
    ) {}

    public function record(int $minute, int $weight, array $records): void
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    public function counts(int $minute, array $ruleIds): array
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    public function offenders(string $ruleId, int $hour, int $limit = self::OFFENDERS): ?array
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    public function clients(string $ruleId, int $hour): ?int
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    public function flushedThrough(): ?int
    {
        throw new LogicException('SPEC-015: not built yet.');
    }

    public function markFlushed(int $minute): void
    {
        throw new LogicException('SPEC-015: not built yet.');
    }
}
