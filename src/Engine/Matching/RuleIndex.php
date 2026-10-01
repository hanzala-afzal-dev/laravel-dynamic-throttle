<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use LogicException;

/**
 * Narrows the rules a request tests to the ones that can match (ADR-0008).
 * Built from the manifest's rules when it loads, never stored (ADR-0023).
 *
 * Rules are grouped by method and by the first path segment of their pattern.
 * A pattern whose first segment is literal can only match paths with that same
 * first segment, so a request tests those, plus every pattern whose first
 * segment has a "*".
 */
final readonly class RuleIndex
{
    /**
     * @param  array<string, array<string, list<int>>>  $literal  method => first segment => positions
     * @param  array<string, list<int>>  $wildcard  method => positions of patterns whose first segment has a "*"
     * @param  list<string>  $patterns  each rule's pattern as it is matched
     */
    private function __construct(
        private array $literal,
        private array $wildcard,
        private array $patterns,
    ) {}

    /**
     * @param  list<CompiledRule>  $rules  in manifest order
     */
    public static function of(array $rules): self
    {
        $literal = [];
        $wildcard = [];
        $patterns = [];

        foreach ($rules as $position => $rule) {
            $pattern = self::normalize($rule->pattern);
            $segment = self::firstSegment($pattern);

            if (str_contains($segment, '*')) {
                $wildcard[$rule->method->value][] = $position;
            } else {
                $literal[$rule->method->value][$segment][] = $position;
            }

            $patterns[] = $pattern;
        }

        return new self($literal, $wildcard, $patterns);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.count($this->patterns));
    }

    /**
     * @param  array<array-key, mixed>  $parts
     */
    public static function fromArray(array $parts, int $count): self
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.count($parts).' '.$count);
    }

    /**
     * The positions of the rules that can match, in manifest order. Each one
     * still has to match its pattern (RuleMatcher).
     *
     * @param  string  $method  as Request::getMethod() returns it
     * @param  string  $path  as Request::decodedPath() returns it
     * @return list<int>
     */
    public function candidates(string $method, string $path): array
    {
        $segment = self::firstSegment($path);
        $candidates = [];

        foreach ($this->ruleMethods($method) as $ruleMethod) {
            array_push($candidates, ...($this->literal[$ruleMethod][$segment] ?? []), ...($this->wildcard[$ruleMethod] ?? []));
        }

        sort($candidates);

        return $candidates;
    }

    /**
     * The pattern of the rule at a position, as it is matched: without a
     * leading slash, except for the root "/".
     */
    public function pattern(int $position): string
    {
        return $this->patterns[$position];
    }

    /**
     * The rule methods that match a request method: itself and "*", and GET
     * for HEAD, because Route::get() registers HEAD too. Each one once, or a
     * request whose method is "*" would be offered each rule twice.
     *
     * @return list<string>
     */
    private function ruleMethods(string $method): array
    {
        $any = RequestMethod::Any->value;

        return match ($method) {
            RequestMethod::Head->value => [$method, RequestMethod::Get->value, $any],
            $any => [$any],
            default => [$method, $any],
        };
    }

    /**
     * A leading slash is ignored, so "/api/*" matches like "api/*". The root stays "/".
     */
    private static function normalize(string $pattern): string
    {
        $pattern = ltrim($pattern, '/');

        return $pattern === '' ? '/' : $pattern;
    }

    /**
     * The text before the first slash: "" for the root "/".
     */
    private static function firstSegment(string $path): string
    {
        $slash = strpos($path, '/');

        return $slash === false ? $path : substr($path, 0, $slash);
    }
}
