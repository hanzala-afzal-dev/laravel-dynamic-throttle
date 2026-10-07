<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Telemetry;

/**
 * One rule's outcome for one request (SPEC-015).
 *
 * @internal
 */
final readonly class TelemetryRecord
{
    public function __construct(
        public string $ruleId,
        /** The key the rule counts the client under (ADR-0024), for the offenders and the distinct clients. */
        public string $identityHash,
        public TelemetryOutcome $outcome,
    ) {}
}
