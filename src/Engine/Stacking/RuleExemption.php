<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * A matched rule that did not count the request, because an exemption frees
 * the client from it (SPEC-009).
 */
final readonly class RuleExemption
{
    public function __construct(
        public CompiledRule $rule,
        public string $exemptionId,
    ) {}
}
