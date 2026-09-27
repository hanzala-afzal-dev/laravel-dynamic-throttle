<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Events;

use Afzal\DynamicThrottle\Enums\Scope;

/**
 * A dry-run rule would have rejected a request: its enforcing twin would have
 * answered 429 (ADR-0012, SPEC-011). Dispatched after the response, once for
 * each such rule, in evaluation order.
 *
 * Plain values only, so a queued listener can serialize it (decision 3). The
 * client is only the hash its counter uses (ADR-0024): a listener that needs
 * the IP or the user reads them from the request.
 */
final readonly class RateLimitWouldBlock
{
    public function __construct(
        public string $ruleId,
        public string $ruleName,
        /** Requests the rule allows per window. */
        public int $limit,
        public int $windowSeconds,
        public Scope $scope,
        /** The key the rule counts the client under, never the identity itself. */
        public string $identityHash,
        /** Whole seconds the client would have waited: the Retry-After it would have got. */
        public int $retryAfter,
        /** As the request sent it, such as GET or HEAD. */
        public string $method,
        /** As rules match it: decoded, without slashes at either end, and "/" for the root. */
        public string $path,
    ) {}
}
