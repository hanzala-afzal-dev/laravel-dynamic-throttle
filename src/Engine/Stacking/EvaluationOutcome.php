<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

/**
 * How evaluating a request's rules ended (SPEC-007).
 */
enum EvaluationOutcome: string
{
    /** Every rule that applied allowed the request. Dry-run rules may have wanted to block it. */
    case Allowed = 'allowed';

    /** An enforcing rule rejected it, and every earlier count was released. */
    case Rejected = 'rejected';

    /**
     * A rule's counter could not be checked, or its identity could not be resolved,
     * and no enforcing rule rejected the request. SPEC-012 decides the response.
     */
    case Failed = 'failed';
}
