<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Manifest\Manifest;
use Illuminate\Support\Str;

/**
 * Finds the rules that match a request (ADR-0008, SPEC-007).
 */
final readonly class RuleMatcher
{
    /**
     * Every matching rule in manifest order: priority, then id. The evaluator
     * applies the per-request cap, because only it knows which rules count
     * the request (ADR-0025).
     *
     * @param  string  $method  as Request::getMethod() returns it
     * @param  string  $path  as Request::decodedPath() returns it (RequestTarget)
     */
    public function match(Manifest $manifest, string $method, string $path): MatchResult
    {
        $rules = [];

        foreach ($manifest->index->candidates($method, $path) as $position) {
            if (Str::is($manifest->index->pattern($position), $path)) {
                $rules[] = $manifest->rule($position);
            }
        }

        return new MatchResult($rules);
    }
}
