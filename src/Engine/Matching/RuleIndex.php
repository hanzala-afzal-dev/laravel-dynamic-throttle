<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Matching;

use Afzal\DynamicThrottle\Enums\RequestMethod;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use InvalidArgumentException;

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
     * @param  array<string, array<array-key, list<int>>>  $literal  method => first segment => positions; PHP keys a numeric segment as an integer
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
     * Its parts as plain values, for L1, which stores them with the rules they were
     * built from (SPEC-028, ADR-0023 as amended).
     *
     * @return array{literal: array<string, array<array-key, list<int>>>, wildcard: array<string, list<int>>, patterns: list<string>}
     */
    public function toArray(): array
    {
        return ['literal' => $this->literal, 'wildcard' => $this->wildcard, 'patterns' => $this->patterns];
    }

    /**
     * The index whose parts toArray() gave, for $count rules. The parts are checked, as
     * an index that disagreed with its rules would offer the wrong ones (ADR-0023): every
     * method is known, and every rule is offered exactly once.
     *
     * @param  array<array-key, mixed>  $parts
     *
     * @throws InvalidArgumentException naming the first part that is wrong
     */
    public static function fromArray(array $parts, int $count): self
    {
        $keys = array_keys($parts);
        sort($keys);

        if ($keys !== ['literal', 'patterns', 'wildcard']) {
            throw new InvalidArgumentException('The index must have exactly the parts literal, wildcard and patterns.');
        }

        if (! is_array($parts['patterns']) || ! array_is_list($parts['patterns']) || count($parts['patterns']) !== $count) {
            throw self::wrongPatterns($count);
        }

        $patterns = [];

        foreach ($parts['patterns'] as $pattern) {
            $patterns[] = is_string($pattern) ? $pattern : throw self::wrongPatterns($count);
        }

        $offered = [];
        $literal = [];

        foreach (is_array($parts['literal']) ? $parts['literal'] : throw self::notMap('literal') as $method => $segments) {
            $method = self::method($method);

            foreach (is_array($segments) ? $segments : throw self::notMap('literal') as $segment => $positions) {
                $literal[$method][$segment] = self::positions(is_array($positions) ? $positions : throw self::notMap('literal'), $count, $offered);
            }
        }

        $wildcard = [];

        foreach (is_array($parts['wildcard']) ? $parts['wildcard'] : throw self::notMap('wildcard') as $method => $positions) {
            $wildcard[self::method($method)] = self::positions($positions, $count, $offered);
        }

        if (count($offered) !== $count) {
            throw new InvalidArgumentException('The index offers '.count($offered)." of its {$count} rules.");
        }

        return new self($literal, $wildcard, $patterns);
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

    private static function wrongPatterns(int $count): InvalidArgumentException
    {
        return new InvalidArgumentException("The index's patterns must be a list of {$count} strings, one for each rule.");
    }

    private static function notMap(string $part): InvalidArgumentException
    {
        return new InvalidArgumentException($part === 'literal'
            ? "The index's literal part must map each method to segments, and each segment to positions."
            : "The index's wildcard part must map each method to positions.");
    }

    /**
     * A method of a stored index: one of RequestMethod's values.
     */
    private static function method(int|string $method): string
    {
        return is_string($method) && RequestMethod::tryFrom($method) instanceof RequestMethod
            ? $method
            : throw new InvalidArgumentException("The index has a method it does not know: \"{$method}\".");
    }

    /**
     * Positions of a stored index: each one a rule's, and offered once in all.
     *
     * @param  array<int, true>  $offered  the positions offered so far, as keys
     * @return list<int>
     */
    private static function positions(mixed $positions, int $count, array &$offered): array
    {
        if (! is_array($positions) || ! array_is_list($positions)) {
            throw new InvalidArgumentException("The index's positions must be lists of integers.");
        }

        $checked = [];

        foreach ($positions as $position) {
            if (! is_int($position)) {
                throw new InvalidArgumentException("The index's positions must be lists of integers.");
            }

            if ($position < 0 || $position >= $count) {
                throw new InvalidArgumentException("The index's position {$position} is not one of its {$count} rules.");
            }

            if (isset($offered[$position])) {
                throw new InvalidArgumentException("The index offers rule {$position} more than once.");
            }

            // Only isset() and count() read it, which see any value alike.
            $offered[$position] = true; // @pest-mutate-ignore: TrueToFalse
            $checked[] = $position;
        }

        return $checked;
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
