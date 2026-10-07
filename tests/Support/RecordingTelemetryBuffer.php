<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Throwable;

/**
 * A telemetry buffer that remembers every call, passes it on to another buffer
 * when it has one, and fails on demand (SPEC-015).
 */
final class RecordingTelemetryBuffer implements TelemetryBuffer
{
    /** @var list<array{int, int, list<array{string, string, string}>}> each record(): the minute, the weight, and each record's rule, hash and outcome */
    public array $recorded = [];

    /** Thrown by record(), when set. */
    public ?Throwable $recordFailure = null;

    public function __construct(private readonly ?TelemetryBuffer $inner = null) {}

    public function record(int $minute, int $weight, array $records): void
    {
        $this->recorded[] = [$minute, $weight, array_map(
            fn (TelemetryRecord $record): array => [$record->ruleId, $record->identityHash, $record->outcome->value],
            $records,
        )];

        if ($this->recordFailure instanceof Throwable) {
            throw $this->recordFailure;
        }

        $this->inner?->record($minute, $weight, $records);
    }

    public function counts(int $minute, array $ruleIds): array
    {
        return $this->inner?->counts($minute, $ruleIds) ?? [];
    }

    public function offenders(string $ruleId, int $hour, int $limit = self::OFFENDERS): ?array
    {
        return $this->inner?->offenders($ruleId, $hour, $limit);
    }

    public function clients(string $ruleId, int $hour): ?int
    {
        return $this->inner?->clients($ruleId, $hour);
    }

    public function flushedThrough(): ?int
    {
        return $this->inner?->flushedThrough();
    }

    public function markFlushed(int $minute): void
    {
        $this->inner?->markFlushed($minute);
    }
}
