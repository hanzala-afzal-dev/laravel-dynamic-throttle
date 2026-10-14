<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Explanations;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;

/*
| SPEC-017 AC-9 to AC-11: POST api/explain answers what dynamic-throttle:explain
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
    Explanations::users(42);

    $printed = Explanations::json('GET', '/api/users', ['--ip' => '203.0.113.7', '--user' => '42', '--header' => ['X-Trace: 1']]);
    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/users', 'ip' => '203.0.113.7', 'user' => '42', 'headers' => ['X-Trace: 1']], DashboardApi::viewer());

    expect([$response->status(), $response->json()])->toBe([200, $printed]);
});

it('AC-10: masks a bearer token and a header value to their last 4 characters, and keeps their hashes', function (): void {
    $ip = Throttling::rule(['name' => 'API']);
    $token = Throttling::rule(['name' => 'Tokens', 'scope' => Scope::ApiToken]);
    $tenant = Throttling::rule(['name' => 'Tenants', 'scope' => Scope::Header, 'scope_parameter' => 'X-Tenant']);
    $headers = ['Authorization: Bearer abcdefgh12345678', 'X-Tenant: acme-corporation'];

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
