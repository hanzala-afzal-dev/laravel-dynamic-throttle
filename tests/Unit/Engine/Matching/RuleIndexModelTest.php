<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;
use Illuminate\Support\Str;
use Random\Engine\Mt19937;
use Random\Randomizer;

covers(RuleIndex::class, RuleMatcher::class);

/*
| SPEC-007 AC-6 (ADR-0016's model-based layer): the pre-index only narrows
| the rules to test, so indexed matching must return exactly what testing
| every rule returns. The reference below is written independently of the
| implementation: its own method rule and its own leading-slash handling.
*/

/**
 * @param  list<CompiledRule>  $rules
 * @return list<string>
 */
function referenceMatches(array $rules, string $method, string $path): array
{
    $matches = [];

    foreach ($rules as $rule) {
        $ruleMethod = $rule->method->value;
        $methodMatches = $ruleMethod === '*' || $ruleMethod === $method || ($ruleMethod === 'GET' && $method === 'HEAD');
        $pattern = trim($rule->pattern, '/') === '' ? '/' : ltrim($rule->pattern, '/');

        if ($methodMatches && Str::is($pattern, $path)) {
            $matches[] = $rule->id;
        }
    }

    return $matches;
}

function randomSegments(Randomizer $random, array $alphabet): string
{
    $segments = [];

    foreach (range(1, $random->getInt(1, 4)) as $ignored) {
        $segments[] = $alphabet[$random->getInt(0, count($alphabet) - 1)];
    }

    return implode('/', $segments);
}

it('AC-6: returns exactly what testing every rule returns, on 10,000 seeded requests', function (): void {
    $random = new Randomizer(new Mt19937(20_260_924));
    $patternParts = ['api', 'web', 'v1', 'users', 'orders', '42', '*', 'ap*', '*s', 'u*rs'];
    $pathParts = ['api', 'web', 'v1', 'users', 'orders', '42', 'apx', 'xs', 'uxrs', '*'];
    $ruleMethods = ['*', 'GET', 'HEAD', 'POST', 'DELETE'];
    $requestMethods = ['GET', 'HEAD', 'POST', 'PUT', 'DELETE'];

    $rules = [];

    foreach (range(1, 200) as $id) {
        $pattern = match ($random->getInt(1, 20)) {
            1 => '/',
            2 => '*',
            3, 4 => '/'.randomSegments($random, $patternParts),
            default => randomSegments($random, $patternParts),
        };
        // Equal priorities, so the manifest order is the id order.
        $rules[] = CompiledRule::fromArray(ManifestFixtures::rule([
            'id' => (string) $id,
            'method' => $ruleMethods[$random->getInt(0, count($ruleMethods) - 1)],
            'pattern' => $pattern,
        ]));
    }

    $manifest = new Manifest(1, $rules);
    $matcher = new RuleMatcher;
    $mismatches = [];
    $matched = 0;

    foreach (range(1, 10_000) as $request) {
        $method = $requestMethods[$random->getInt(0, count($requestMethods) - 1)];
        $path = $random->getInt(1, 20) === 1 ? '/' : randomSegments($random, $pathParts);

        $expected = referenceMatches($rules, $method, $path);
        $actual = array_map(fn (CompiledRule $rule): string => $rule->id, $matcher->match($manifest, $method, $path)->rules);
        $matched += count($expected);

        if ($actual !== $expected) {
            $mismatches[] = "{$method} {$path}: expected ".implode(',', $expected).', got '.implode(',', $actual);
        }
    }

    expect($mismatches)->toHaveCount(0, implode("\n", array_slice($mismatches, 0, 5)))
        // The traffic must produce many matches, or agreeing on "none" would prove little.
        ->and($matched)->toBeGreaterThan(10_000);
});
