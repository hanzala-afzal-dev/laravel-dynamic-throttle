<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Explanation;

/**
 * What the next request gets (SPEC-014): the evaluator's outcomes, and two of
 * explain's own.
 *
 * @internal
 */
enum DecisionOutcome: string
{
    case Allowed = 'allowed';

    /** An enforcing rule answers 429. */
    case Rejected = 'rejected';

    /** A fail-open rule could not be checked: the request goes through. */
    case Failed = 'failed';

    /** A fail-closed rule could not be checked: it answers 503. */
    case Unavailable = 'unavailable';

    /** A step before the rules lets the request through. */
    case Untouched = 'untouched';

    /** The counters cannot be read, and an enforcing rule's counter decides. */
    case Unknown = 'unknown';
}
