<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Identities;
use Illuminate\Cache\ArrayStore;

/*
| SPEC-008 AC-14 (ADR-0009): whatever the scope, no raw identifier reaches a
| cache key. The raw values are distinctive, so a hash can never contain them
| by chance.
*/

it('AC-14: writes no raw identifier into any key, whatever the scope', function (): void {
    config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
    DynamicThrottle::scope('plan', fn (): string => 'plan-QWERTY');
    $this->actingAs(new TestUser(['id' => 'user-QWERTY']));
    $array = new ArrayStore;
    $request = Identities::request('203.0.113.7', ['Authorization' => 'Bearer tok-QWERTY', 'X-Client-Id' => 'client-QWERTY']);
    $rules = [
        Identities::rule('ip', id: 'ip'),
        Identities::rule('user', id: 'user'),
        Identities::rule('api_token', id: 'token'),
        Identities::rule('header', parameter: 'X-Client-Id', id: 'header'),
        Identities::rule('global', id: 'global'),
        Identities::rule('custom', parameter: 'plan', id: 'custom'),
    ];
    $resolver = Identities::resolver($request);

    // Six enforcing rules, one per scope: the cap must let all of them count (ADR-0025).
    $evaluation = (new RuleEvaluator(Identities::store($array), count($rules)))->evaluate($rules, $resolver);
    $keys = implode("\n", (fn (): array => array_keys($this->storage))->call($array));

    expect($evaluation->outcome)->toBe(EvaluationOutcome::Allowed)
        ->and($evaluation->attempts)->toHaveCount(6)
        ->and($keys)->not->toBe('')
        ->not->toContain('tok-QWERTY')
        ->not->toContain('client-QWERTY')
        ->not->toContain('plan-QWERTY')
        ->not->toContain('user-QWERTY')
        ->not->toContain('203.0.113.7')
        ->and(array_map($resolver->resolve(...), $rules))->each->toMatch('/\A[0-9a-f]{64}\z/');
});
