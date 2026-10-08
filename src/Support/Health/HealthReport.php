<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use JsonSerializable;

/**
 * Every check's finding, in order, and the report's status: an error when a check
 * errs, else a warning when one warns, else ok. A skipped check counts as ok
 * (SPEC-016 AC-2).
 *
 * @internal
 */
final readonly class HealthReport implements JsonSerializable
{
    /**
     * @param  list<CheckResult>  $results
     */
    public function __construct(
        public array $results,
    ) {}

    public function status(): CheckStatus
    {
        return match (true) {
            $this->count(CheckStatus::Error) > 0 => CheckStatus::Error,
            $this->count(CheckStatus::Warning) > 0 => CheckStatus::Warning,
            default => CheckStatus::Ok,
        };
    }

    public function count(CheckStatus $status): int
    {
        return count(array_filter($this->results, fn (CheckResult $result): bool => $result->status === $status));
    }

    /**
     * @return array{status: string, checks: list<CheckResult>}
     */
    public function jsonSerialize(): array
    {
        return ['status' => $this->status()->value, 'checks' => $this->results];
    }
}
