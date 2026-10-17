<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Engine\Contracts\TelemetryBuffer;
use Afzal\DynamicThrottle\Engine\Telemetry\FeedEntry;
use Afzal\DynamicThrottle\Engine\Telemetry\TelemetryRecord;
use Closure;
use Throwable;

/**
 * A telemetry buffer that remembers every call, passes it on to another buffer
 * when it has one, and fails on demand (SPEC-015, SPEC-016).
 */
final class RecordingTelemetryBuffer implements TelemetryBuffer
{
    /** @var list<array{int, int, list<array{string, string, string}>}> each record(): the minute, the weight, and each record's rule, hash and outcome */
    public array $recorded = [];

    /** @var list<?string> each record()'s client, the `ip` scope's identity hash, in order (SPEC-016) */
    public array $clients = [];

    /** @var list<list<FeedEntry>> each record()'s feed entries, in order (SPEC-030) */
    public array $feeds = [];

    /** @var list<int> the minutes counts() was asked for, in order */
    public array $countsAsked = [];

    /** Thrown by record(), when set. */
    public ?Throwable $recordFailure = null;

    /** Thrown by counts() for these minutes, when set. */
    public ?Throwable $countsFailure = null;

    /** @var list<int> the minutes counts() fails for; empty fails every minute */
    public array $failingMinutes = [];

    /** Runs once, before the first counts(), as another flush would meanwhile. */
    public ?Closure $beforeFirstCounts = null;

    public function __construct(private readonly ?TelemetryBuffer $inner = null) {}

    public function record(int $minute, int $weight, array $records, ?string $client = null, array $feed = []): void
    {
        $this->feeds[] = $feed;
        $this->recorded[] = [$minute, $weight, array_map(
            fn (TelemetryRecord $record): array => [$record->ruleId, $record->identityHash, $record->outcome->value],
            $records,
        )];
        $this->clients[] = $client;

        if ($this->recordFailure instanceof Throwable) {
            throw $this->recordFailure;
        }

        $this->inner?->record($minute, $weight, $records, $client, $feed);
    }

    public function counts(int $minute, array $ruleIds): array
    {
        $this->countsAsked[] = $minute;

        if ($this->beforeFirstCounts instanceof Closure) {
            $hook = $this->beforeFirstCounts;
            $this->beforeFirstCounts = null;
            $hook();
        }

        if ($this->countsFailure instanceof Throwable && ($this->failingMinutes === [] || in_array($minute, $this->failingMinutes, true))) {
            throw $this->countsFailure;
        }

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

    public function topClients(int $hour, int $limit = 1): ?array
    {
        return $this->inner?->topClients($hour, $limit);
    }

    public function requests(int $hour): ?int
    {
        return $this->inner?->requests($hour);
    }

    public function feed(int $minute, ?int $since = null): ?array
    {
        return $this->inner?->feed($minute, $since);
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
