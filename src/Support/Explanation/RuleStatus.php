<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

/**
 * What a matched rule does with the request (SPEC-014).
 *
 * @internal
 */
enum RuleStatus: string
{
    /** It counts the request, under the identity shown. */
    case Counts = 'counts';

    /** An exemption frees the client from it (SPEC-009). */
    case Exempt = 'exempt';

    /** The request has no identity for it, and it skips such requests (ADR-0022). */
    case Skipped = 'skipped';

    /** Over its mode's cap of rules per request (ADR-0025). */
    case Ignored = 'ignored';

    /** Its identity could not be resolved (SPEC-008). */
    case Failed = 'failed';

    /** An earlier fail-closed rule stopped evaluation (SPEC-012). */
    case NotReached = 'not_reached';
}
