<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Events;

use Afzal\DynamicThrottle\Enums\Scope;

/**
 * An enforcing rule rejected a request with a 429 (ADR-0013 point 6, SPEC-015).
 * Dispatched after the response, once per rejected request, after the
 * request's RateLimitWouldBlock events.
 *
 * It carries RateLimitWouldBlock's values, so one listener can handle both, and
 * plain values only, so a queued listener can serialize it. The client is only
 * the hash its counter uses (ADR-0024).
 */
final readonly class RateLimitExceeded
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
        /** Whole seconds the client must wait: the response's Retry-After. */
        public int $retryAfter,
        /** As the request sent it, such as GET or HEAD. */
        public string $method,
        /** As rules match it: decoded, without slashes at either end, and "/" for the root. */
        public string $path,
    ) {}
}
