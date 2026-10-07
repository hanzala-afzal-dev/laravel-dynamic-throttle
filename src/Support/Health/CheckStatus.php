<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

/**
 * What a health check found (SPEC-016 decision 2). The values are public once
 * released (ADR-0017).
 *
 * @internal
 */
enum CheckStatus: string
{
    case Ok = 'ok';

    /** Something limits less than the admin thinks (decision 3). */
    case Warning = 'warning';

    /** Something breaks limiting outright (decision 3). */
    case Error = 'error';

    /** The check does not apply, or cannot tell: it counts as ok. */
    case Skipped = 'skipped';
}
