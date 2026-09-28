<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

/**
 * How evaluating a request's rules ended (SPEC-007).
 */
enum EvaluationOutcome: string
{
    /**
     * Every rule that applied allowed the request, and none failed. Dry-run rules may
     * have wanted to block it. During the store's cooldown, the rules that applied
     * went unchecked (SPEC-012).
     */
    case Allowed = 'allowed';

    /** An enforcing rule rejected it, and every earlier count was released. */
    case Rejected = 'rejected';

    /**
     * A rule's counter could not be checked, or its identity could not be resolved,
     * and no enforcing rule rejected the request. It goes through (ADR-0010).
     */
    case Failed = 'failed';

    /**
     * An enforcing fail-closed rule applied, and its counter could not be checked:
     * the store failed or is in its cooldown, or the rule's identity could not be
     * resolved. The request gets a 503 (ADR-0010 point 2, SPEC-012).
     */
    case Unavailable = 'unavailable';
}
