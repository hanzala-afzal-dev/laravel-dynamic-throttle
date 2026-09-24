<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\Manifest;
use Illuminate\Support\Str;

/**
 * Finds the rules that apply to a request (ADR-0008, SPEC-007).
 */
final readonly class RuleMatcher
{
    public function __construct(
        /** matching.max_rules_per_request: later matches are ignored. */
        private int $maxRulesPerRequest = 5,
    ) {}

    /**
     * The matching rules in manifest order (priority, then id), up to the cap.
     *
     * @param  string  $method  as Request::getMethod() returns it
     * @param  string  $path  as Request::decodedPath() returns it (RequestTarget)
     */
    public function match(Manifest $manifest, string $method, string $path): MatchResult
    {
        $rules = [];
        $ignored = 0;

        foreach ($manifest->index->candidates($method, $path) as $position) {
            if (! Str::is($manifest->index->pattern($position), $path)) {
                continue;
            }

            if (count($rules) < $this->maxRulesPerRequest) {
                $rules[] = $manifest->rules[$position];
            } else {
                $ignored++;
            }
        }

        return new MatchResult($rules, $ignored);
    }
}
