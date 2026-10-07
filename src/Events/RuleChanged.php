<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Events;

use Afzal\DynamicThrottle\Enums\RuleChange;

/**
 * A rule was created, updated or deleted (ADR-0013 point 6, SPEC-015).
 * Dispatched after the change commits, never for one that rolls back.
 *
 * Plain values only, so a queued listener can serialize it. The attributes are
 * the rule's columns, without its timestamps, with enums as their values, as the
 * audit log keeps them (SPEC-013).
 */
final readonly class RuleChanged
{
    /**
     * @param  ?array<string, mixed>  $before  null for a creation; only the changed attributes for an update
     * @param  ?array<string, mixed>  $after  null for a deletion; only the changed attributes for an update
     */
    public function __construct(
        public string $ruleId,
        public string $ruleName,
        public RuleChange $change,
        public ?array $before,
        public ?array $after,
    ) {}
}
