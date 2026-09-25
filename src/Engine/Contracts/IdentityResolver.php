<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Contracts;

use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * Who a rule counts this request for (ADR-0009). SPEC-008 implements it.
 */
interface IdentityResolver
{
    /**
     * The id of the exemption that frees the client from this rule, or null
     * (SPEC-009). The evaluator asks before resolve(), and resolves nothing for
     * an exempt rule.
     *
     * @throws IdentityUnavailableException when no exemption matched and one could
     *                                      not be checked; the rule then counts
     */
    public function exemption(CompiledRule $rule): ?string;

    /**
     * The SHA-256 hash of the client's identity for this rule, or null when the rule does
     * not apply to this request: a missing identity with "skip" (ADR-0022), or an exemption.
     *
     * @throws IdentityUnavailableException when the identity cannot be resolved; the
     *                                      evaluator then fails the rule (SPEC-008)
     */
    public function resolve(CompiledRule $rule): ?string;
}
