<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Enums\RuleMode;

/**
 * The rate-limit headers of a response (ADR-0008, SPEC-010), named and
 * ordered as Laravel's throttle middleware sends them.
 */
final class RateLimitHeaders
{
    /**
     * A request that went through: the limit and remaining of the enforcing rule that
     * counted it with the fewest requests left, the first in evaluation order among
     * equals. None when no enforcing rule counted it, because a dry-run rule sets no
     * limit the client has to respect (ADR-0012).
     *
     * @return array<string, int>
     */
    public static function counted(Evaluation $evaluation): array
    {
        $tightest = null;

        foreach ($evaluation->attempts as $counted) {
            if ($counted->rule->mode === RuleMode::Enforce && (! $tightest instanceof RuleAttempt || $counted->attempt->remaining < $tightest->attempt->remaining)) {
                $tightest = $counted;
            }
        }

        return $tightest instanceof RuleAttempt ? [
            'X-RateLimit-Limit' => $tightest->attempt->limit,
            'X-RateLimit-Remaining' => $tightest->attempt->remaining,
        ] : [];
    }

    /**
     * A rejected request: the rejecting rule's limit, no request left, and when the
     * client may retry, in seconds to wait and as a Unix time.
     *
     * @param  int  $now  Unix seconds
     * @return array<string, int>
     */
    public static function rejected(RuleAttempt $rejection, int $now): array
    {
        return [
            'X-RateLimit-Limit' => $rejection->attempt->limit,
            'X-RateLimit-Remaining' => 0,
            'Retry-After' => $rejection->attempt->retryAfter,
            'X-RateLimit-Reset' => $now + $rejection->attempt->retryAfter,
        ];
    }
}
