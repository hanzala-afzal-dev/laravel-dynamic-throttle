<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Matching\MatchResult;
use Afzal\DynamicThrottle\Engine\Matching\RuleIndex;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(RuleMatcher::class, RuleIndex::class, MatchResult::class);

/*
| SPEC-007 AC-2 to AC-5, AC-7 and AC-8: which rules match a request's method
| and decoded path, in which order, and how many apply.
*/

function matchingRule(string $id, string $method, string $pattern, int $priority = 100): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule([
        'id' => $id,
        'method' => $method,
        'pattern' => $pattern,
        'priority' => $priority,
    ]));
}

/**
 * @return list<string>
 */
function matchedIds(Manifest $manifest, string $method, string $path, int $cap = 5): array
{
    return array_map(fn (CompiledRule $rule): string => $rule->id, (new RuleMatcher($cap))->match($manifest, $method, $path)->rules);
}

it('AC-2: matches methods, and GET rules also match HEAD', function (string $ruleMethod, string $requestMethod, bool $matches): void {
    $manifest = new Manifest(1, [matchingRule('1', $ruleMethod, 'api/*')]);

    expect(matchedIds($manifest, $requestMethod, 'api/x'))->toBe($matches ? ['1'] : []);
})->with([
    '* matches GET' => ['*', 'GET', true],
    '* matches POST' => ['*', 'POST', true],
    '* matches DELETE' => ['*', 'DELETE', true],
    'POST matches POST' => ['POST', 'POST', true],
    'POST does not match GET' => ['POST', 'GET', false],
    'GET matches GET' => ['GET', 'GET', true],
    'GET matches HEAD' => ['GET', 'HEAD', true],
    'GET does not match POST' => ['GET', 'POST', false],
    'HEAD matches HEAD' => ['HEAD', 'HEAD', true],
    'HEAD does not match GET' => ['HEAD', 'GET', false],
]);

it('AC-3: matches patterns with Str::is on the whole path, ignoring a leading slash', function (string $pattern, string $path, bool $matches): void {
    $manifest = new Manifest(1, [matchingRule('1', '*', $pattern)]);

    expect(matchedIds($manifest, 'GET', $path))->toBe($matches ? ['1'] : []);
})->with([
    'api/* matches api/users' => ['api/*', 'api/users', true],
    'api/* matches api/users/5' => ['api/*', 'api/users/5', true],
    'api/* does not match api' => ['api/*', 'api', false],
    'api/* does not match apiv2/users' => ['api/*', 'apiv2/users', false],
    'api/*/posts matches api/1/posts' => ['api/*/posts', 'api/1/posts', true],
    'api/*/posts matches api/1/2/posts' => ['api/*/posts', 'api/1/2/posts', true],
    'api/*/posts does not match api/1/posts/9' => ['api/*/posts', 'api/1/posts/9', false],
    '* matches the root' => ['*', '/', true],
    '* matches api' => ['*', 'api', true],
    '* matches a/b/c' => ['*', 'a/b/c', true],
    '/ matches the root' => ['/', '/', true],
    '/ does not match api' => ['/', 'api', false],
    'api/users matches itself' => ['api/users', 'api/users', true],
    'api/users does not match api/users/5' => ['api/users', 'api/users/5', false],
    'api/users does not match API/users' => ['api/users', 'API/users', false],
    '/api/* matches api/users' => ['/api/*', 'api/users', true],
    '/api/* does not match web/users' => ['/api/*', 'web/users', false],
]);

it('AC-4: returns matches in manifest order, whichever index group they come from', function (): void {
    // Priority order, as the compiler writes it; the three sit in different index groups.
    $manifest = new Manifest(1, [
        matchingRule('10', '*', '*', priority: 10),
        matchingRule('20', 'GET', 'api/*', priority: 20),
        matchingRule('30', '*', 'api/users', priority: 30),
    ]);

    expect(matchedIds($manifest, 'GET', 'api/users'))->toBe(['10', '20', '30']);
});

it('AC-5: offers only candidates that can match, then keeps the ones that do', function (): void {
    $manifest = new Manifest(1, [
        matchingRule('1', 'GET', 'api/*'),
        matchingRule('2', '*', 'api/users'),
        matchingRule('3', 'POST', 'api/*'),
        matchingRule('4', 'GET', 'web/*'),
        matchingRule('5', '*', '*'),
        matchingRule('6', 'GET', 'ap*/x'),
        matchingRule('7', '*', '/'),
    ]);

    $candidates = array_map(fn (int $position): string => $manifest->rules[$position]->id, $manifest->index->candidates('GET', 'api/users'));

    expect($candidates)->toBe(['1', '2', '5', '6'])
        ->and(matchedIds($manifest, 'GET', 'api/users'))->toBe(['1', '2', '5']);
});

it('AC-5: offers HEAD requests the GET candidates too, and a path whose first segment is * each candidate once', function (): void {
    $manifest = new Manifest(1, [
        matchingRule('1', 'GET', 'api/*'),
        matchingRule('2', 'HEAD', '*'),
        matchingRule('3', '*', 'ap*'),
    ]);

    $ids = fn (string $method, string $path): array => array_map(
        fn (int $position): string => $manifest->rules[$position]->id,
        $manifest->index->candidates($method, $path),
    );

    expect($ids('HEAD', 'api/users'))->toBe(['1', '2', '3'])
        ->and($ids('GET', '*/x'))->toBe(['3']);
});

it('AC-7: applies only the first max_rules_per_request matches, by priority, and counts the rest', function (): void {
    $rules = array_map(fn (int $priority): CompiledRule => matchingRule((string) $priority, '*', '*', $priority), range(1, 7));
    $manifest = new Manifest(1, $rules);

    $default = (new RuleMatcher)->match($manifest, 'GET', 'api');
    $capped = (new RuleMatcher(3))->match($manifest, 'GET', 'api');

    expect(array_map(fn (CompiledRule $rule): string => $rule->id, $default->rules))->toBe(['1', '2', '3', '4', '5'])
        ->and($default->ignored)->toBe(2)
        ->and(array_map(fn (CompiledRule $rule): string => $rule->id, $capped->rules))->toBe(['1', '2', '3'])
        ->and($capped->ignored)->toBe(4);
});

it('AC-8: matches nothing in an empty or missing manifest', function (Manifest $manifest): void {
    $result = (new RuleMatcher)->match($manifest, 'GET', 'api/users');

    expect([$result->rules, $result->ignored])->toBe([[], 0]);
})->with([
    'empty' => fn (): Manifest => new Manifest(1, []),
    'missing' => fn (): Manifest => Manifest::missing(),
]);
