<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Identity\ScopeIdentity;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Tests\Support\ManifestFixtures;

covers(ScopeIdentity::class);

/*
| SPEC-008: the identity a rule counts a request under, before hashing
| (ADR-0009, ADR-0022). A present identity and a fallback use different
| prefixes, so they can never share a counter.
*/

/**
 * The client IP, read only when an identity needs it.
 */
function identityIp(string $ip = '203.0.113.7'): Closure
{
    return fn (): string => $ip;
}

function identityRule(string $scope, string $missing = 'ip'): CompiledRule
{
    return CompiledRule::fromArray(ManifestFixtures::rule([
        'scope' => $scope,
        'scope_parameter' => in_array($scope, ['header', 'custom'], true) ? 'x' : null,
        'on_missing_identity' => $missing,
    ]));
}

it('gives each scope its identity', function (string $scope, ?string $value, string $identity): void {
    expect(ScopeIdentity::for(identityRule($scope), $value, identityIp()))->toBe($identity);
})->with([
    'AC-1: ip' => ['ip', null, 'ip:203.0.113.7'],
    'AC-1: ip ignores a value' => ['ip', 'anything', 'ip:203.0.113.7'],
    'AC-3: user' => ['user', 'App\Models\User:42', 'user:App\Models\User:42'],
    'AC-7: api_token' => ['api_token', 'abc', 'token:abc'],
    'AC-8: header' => ['header', 'c1', 'header:c1'],
    'AC-9: global' => ['global', null, 'global'],
    'AC-9: global ignores a value' => ['global', 'anything', 'global'],
    'AC-10: custom' => ['custom', 'gold', 'custom:gold'],
]);

it('falls back to the scope\'s IP identity when the identity is missing', function (string $scope, ?string $value, string $identity): void {
    expect(ScopeIdentity::for(identityRule($scope), $value, identityIp()))->toBe($identity);
})->with([
    'AC-4: a guest' => ['user', null, 'guest:203.0.113.7'],
    'AC-4: a guest with an empty id' => ['user', '', 'guest:203.0.113.7'],
    'AC-7: no token' => ['api_token', null, 'anon:203.0.113.7'],
    'AC-7: an empty token' => ['api_token', '', 'anon:203.0.113.7'],
    'AC-8: no header' => ['header', null, 'missing:203.0.113.7'],
    'AC-8: an empty header' => ['header', '', 'missing:203.0.113.7'],
    'AC-10: no custom value' => ['custom', null, 'missing:203.0.113.7'],
    'AC-10: an empty custom value' => ['custom', '', 'missing:203.0.113.7'],
]);

it('skips the rule when the identity is missing and the rule says skip', function (string $scope, ?string $value): void {
    expect(ScopeIdentity::for(identityRule($scope, 'skip'), $value, identityIp()))->toBeNull();
})->with([
    'AC-4: a guest' => ['user', null],
    'AC-7: an empty token' => ['api_token', ''],
    'AC-8: no header' => ['header', null],
    'AC-10: an empty custom value' => ['custom', ''],
]);

it('counts a present identity on a skip rule, and never skips ip or global', function (): void {
    expect(ScopeIdentity::for(identityRule('user', 'skip'), 'App\Models\User:42', identityIp()))->toBe('user:App\Models\User:42')
        ->and(ScopeIdentity::for(identityRule('ip', 'skip'), null, identityIp()))->toBe('ip:203.0.113.7')
        ->and(ScopeIdentity::for(identityRule('global', 'skip'), null, identityIp()))->toBe('global');
});

it('uses the IP it is given for fallbacks, grouped or unknown', function (): void {
    expect(ScopeIdentity::for(identityRule('user'), null, identityIp('2001:db8:1:2::/64')))->toBe('guest:2001:db8:1:2::/64')
        ->and(ScopeIdentity::for(identityRule('api_token'), null, identityIp('unknown')))->toBe('anon:unknown');
});

it('reads the client IP only when the identity needs it', function (string $scope, string $missing, ?string $value, ?string $identity): void {
    // Symfony throws when trusted proxies send conflicting headers; a rule that needs no IP must still resolve.
    $unreadable = fn (): string => throw new RuntimeException('The client IP cannot be read.');

    expect(ScopeIdentity::for(identityRule($scope, $missing), $value, $unreadable))->toBe($identity);
})->with([
    'global' => ['global', 'ip', null, 'global'],
    'a present user' => ['user', 'ip', 'App\Models\User:42', 'user:App\Models\User:42'],
    'a present token' => ['api_token', 'ip', 'abc', 'token:abc'],
    'a present header' => ['header', 'ip', 'c1', 'header:c1'],
    'a present custom value' => ['custom', 'ip', 'gold', 'custom:gold'],
    'a guest on a skip rule' => ['user', 'skip', null, null],
]);
