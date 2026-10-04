<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

use Afzal\DynamicThrottle\Engine\CounterReading;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use JsonSerializable;

/**
 * A matched rule, and what it does with the request (SPEC-014).
 *
 * @internal
 */
final readonly class ExplainedRule implements JsonSerializable
{
    /**
     * @param  int  $limit  under the scale factor (SPEC-013)
     * @param  ?string  $identity  the identity as given, which is hashed (ADR-0024), for a rule that counts
     * @param  ?string  $exemption  the exemption that frees the client
     * @param  ?string  $error  why its identity, or the exemption check, failed
     * @param  ?CounterReading  $counter  its counter for the identity, when it could be read
     * @param  ?string  $stoppedBy  the fail-closed rule that stopped evaluation before it
     */
    public function __construct(
        public CompiledRule $rule,
        public int $limit,
        public RuleStatus $status,
        public ?string $identity = null,
        public ?string $hash = null,
        public ?string $exemption = null,
        public ?string $error = null,
        public ?CounterReading $counter = null,
        public ?string $stoppedBy = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->rule->id,
            'name' => $this->rule->name,
            'method' => $this->rule->method->value,
            'pattern' => $this->rule->pattern,
            'priority' => $this->rule->priority,
            'mode' => $this->rule->mode->value,
            'limit' => $this->limit,
            'max_attempts' => $this->rule->maxAttempts,
            'window_seconds' => $this->rule->windowSeconds,
            'on_failure' => $this->rule->onFailure->value,
            'scope' => $this->rule->scope->value,
            'scope_parameter' => $this->rule->scopeParameter,
            'status' => $this->status->value,
            'identity' => $this->identity,
            'hash' => $this->hash,
            'exemption' => $this->exemption,
            'error' => $this->error,
            'counter' => $this->counter instanceof CounterReading ? [
                'count' => $this->counter->count,
                'remaining' => $this->counter->remaining(),
                'next' => $this->counter->next->allowed ? 'allowed' : 'limited',
                'retry_after' => $this->counter->next->allowed ? null : $this->counter->next->retryAfter,
            ] : null,
        ];
    }
}
