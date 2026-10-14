<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Facades\DynamicThrottle;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Support\Explainer;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationRequest;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Explanations;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/*
| SPEC-017 AC-9 to AC-11 and AC-24: POST api/explain answers what dynamic-throttle:explain
| --json prints, with bearer tokens and header values masked (decision 3), and
| refuses input it cannot explain. The clock stands at 2027-01-15 12:00:00 UTC.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-9: answers what the CLI prints for the same request, to a viewer', function (): void {
    Throttling::rule(['name' => 'API']);
    Throttling::rule(['name' => 'Users', 'pattern' => 'api/users', 'scope' => Scope::User]);
    Throttling::route('api/users');
    Explanations::users(42);

    $printed = Explanations::json('GET', '/api/users', ['--ip' => '203.0.113.7', '--user' => '42', '--header' => ['X-Trace: 1']]);
    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/users', 'ip' => '203.0.113.7', 'user' => '42', 'headers' => ['X-Trace: 1']], DashboardApi::viewer());

    expect([$response->status(), $response->json()])->toBe([200, $printed])
        ->and(count($printed['rules']))->toBe(2);
});

it('AC-10: masks a bearer token and a header value to their last 4 characters, and keeps their hashes', function (): void {
    $ip = Throttling::rule(['name' => 'API']);
    $token = Throttling::rule(['name' => 'Tokens', 'scope' => Scope::ApiToken]);
    $tenant = Throttling::rule(['name' => 'Tenants', 'scope' => Scope::Header, 'scope_parameter' => 'X-Tenant']);
    $headers = ['Authorization: Bearer abcdefgh12345678', 'X-Tenant: acme-corporation'];
    Throttling::route('api/orders');

    $printed = Explanations::rules(Explanations::json('GET', '/api/orders', ['--ip' => '203.0.113.7', '--header' => $headers]));
    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/orders', 'ip' => '203.0.113.7', 'headers' => $headers], DashboardApi::viewer());
    $answered = Explanations::rules($response->json());

    expect([$answered[(string) $token->id]['identity'], $answered[(string) $tenant->id]['identity'], $answered[(string) $ip->id]['identity']])
        ->toBe(['token:…5678', 'header:…tion', 'ip:203.0.113.7'])
        ->and(array_map(fn (array $rule): mixed => $rule['hash'], $answered))->toBe(array_map(fn (array $rule): mixed => $rule['hash'], $printed))
        ->and($printed[(string) $token->id]['identity'])->toBe('token:abcdefgh12345678');
});

it('AC-10: hides a value under 12 characters whole', function (): void {
    $token = Throttling::rule(['name' => 'Tokens', 'scope' => Scope::ApiToken]);
    $tenant = Throttling::rule(['name' => 'Tenants', 'scope' => Scope::Header, 'scope_parameter' => 'X-Tenant']);
    Throttling::route('api/orders');

    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/orders', 'headers' => ['Authorization: Bearer short', 'X-Tenant: acme']], DashboardApi::viewer());
    $answered = Explanations::rules($response->json());

    expect([$answered[(string) $token->id]['identity'], $answered[(string) $tenant->id]['identity']])->toBe(['token:…', 'header:…']);
});

it('AC-11: refuses input it cannot explain with a 422 that names the field', function (array $input, string $field): void {
    Throttling::rule();

    $response = DashboardApi::send($this, 'POST', 'explain', [...['method' => 'GET', 'path' => '/api/orders'], ...$input], DashboardApi::viewer());

    expect([$response->status(), array_keys($response->json('errors') ?? [])])->toBe([422, [$field]]);
})->with([
    'a bad method' => [['method' => 'FETCH'], 'method'],
    'an IP that is not one' => [['ip' => 'nope'], 'ip'],
    'a guard without a user' => [['guard' => 'web'], 'guard'],
    'a header without a colon' => [['headers' => ['NoColon']], 'headers.0'],
    'more than 20 headers' => [['headers' => array_fill(0, 21, 'X-Trace: 1')], 'headers'],
    'a path over 2,048 characters' => [['path' => '/'.str_repeat('a', 2_048)], 'path'],
]);

/*
| AC-24, found while building AC-9: in a dashboard request, the admin who sends
| it is logged in, and explain must never take them for the request it explains.
*/

it('AC-24: counts a guest where the explained request has no user, never the admin who sends it', function (array $guards): void {
    config()->set('dynamic-throttle.scopes.user.guards', $guards);
    $rule = Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Throttling::route('api/orders');

    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/orders', 'ip' => '203.0.113.7'], DashboardApi::viewer());

    expect(Explanations::rules($response->json())[(string) $rule->id]['identity'] ?? null)->toBe('guest:203.0.113.7');
})->with([
    'the guard the request authenticated with' => [[null]],
    'scopes.user.guards' => [[['web']]],
]);

it('AC-24: gives a custom scope that reads the request\'s user no user', function (): void {
    DynamicThrottle::scope('account', fn (Request $request): ?string => $request->user() === null ? null : 'account-of-'.$request->user()->getAuthIdentifier());
    $rule = Throttling::rule(['name' => 'Accounts', 'scope' => Scope::Custom, 'scope_parameter' => 'account']);
    Throttling::route('api/orders');

    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/orders', 'ip' => '203.0.113.7'], DashboardApi::viewer());

    expect(Explanations::rules($response->json())[(string) $rule->id]['identity'] ?? null)->toBe('missing:203.0.113.7');
});

it('AC-24: counts the explained user where the middleware sees one, and leaves the admin logged in', function (): void {
    $rule = Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Throttling::route('api/orders', ['auth', 'dynamic.throttle']);
    Explanations::users(42);
    $admin = DashboardApi::viewer();
    $this->actingAs($admin);

    $explanation = app(Explainer::class)->explain(ExplanationRequest::of('GET', '/api/orders', '203.0.113.7', '42'));
    $guest = app(Explainer::class)->explain(ExplanationRequest::of('GET', '/api/orders', '203.0.113.7'));

    expect([$explanation->rules[0]->identity ?? null, $guest->rules[0]->identity ?? null])
        ->toBe(['user:'.User::class.':42', 'guest:203.0.113.7'])
        ->and([Auth::user(), Auth::guard('web')->user(), $rule->id])->toBe([$admin, $admin, $rule->id]);
});
