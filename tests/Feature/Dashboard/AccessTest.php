<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-018 AC-1 to AC-8: the dashboard's route group, its gates, its own
| Laravel limit and its headers, through GET api/me. User 1 is the viewer
| and user 2 the manager, when the test defines the gates.
*/

beforeEach(function (): void {
    Dashboard::boot();
});

const ME = '/dynamic-throttle/api/me';

function viewer(): TestUser
{
    return new TestUser(['id' => 1]);
}

function manager(): TestUser
{
    return new TestUser(['id' => 2]);
}

/**
 * The gates an app defines: user 1 may view, user 2 may manage, and guests neither.
 */
function definedGates(): void
{
    Dashboard::gates(
        fn (TestUser $user): bool => $user->getAuthIdentifier() === 1,
        fn (TestUser $user): bool => $user->getAuthIdentifier() === 2,
    );
}

/**
 * The status, and the can object for a 200.
 *
 * @param  TestResponse<Response>  $response
 * @return array{int, mixed}
 */
function access(TestResponse $response): array
{
    return [$response->status(), $response->status() === 200 ? $response->json('can') : null];
}

/*
| AC-1: the routes.
*/

it('AC-1: serves api/me at /dynamic-throttle, named dynamic-throttle.api.me, behind the web group', function (): void {
    Dashboard::environment('local');

    $route = Route::getRoutes()->getByName('dynamic-throttle.api.me');

    expect($route?->uri())->toBe('dynamic-throttle/api/me')
        ->and($route?->gatherMiddleware())->toContain('web')
        ->and($this->getJson(ME)->status())->toBe(200);
});

it('AC-1: serves it under dashboard.path, trimmed of its slashes, and no longer at the default path', function (): void {
    Dashboard::boot(['path' => '/ops/throttle/']);
    Dashboard::environment('local');

    expect($this->getJson('/ops/throttle/api/me')->status())->toBe(200)
        ->and($this->getJson(ME)->status())->toBe(404);
});

it('AC-1: serves it only on dashboard.domain when one is set', function (): void {
    Dashboard::boot(['domain' => 'admin.example.test']);
    Dashboard::environment('local');

    expect($this->getJson('http://admin.example.test'.ME)->status())->toBe(200)
        ->and($this->getJson('http://www.example.test'.ME)->status())->toBe(404);
});

it('AC-1: registers no route of its own with dashboard.enabled off, or with an empty path', function (array $config): void {
    Dashboard::boot($config);

    $names = array_filter(array_map(fn (\Illuminate\Routing\Route $route): ?string => $route->getName(), Route::getRoutes()->getRoutes()));

    expect(array_filter($names, fn (string $name): bool => str_starts_with($name, 'dynamic-throttle.')))->toBe([]);
})->with([
    'disabled' => [['enabled' => false]],
    'an empty path' => [['path' => '/']],
]);

/*
| AC-2 to AC-5: the gates.
*/

it('AC-2: lets guests and users in, with every ability, in local when the app defines no gate', function (): void {
    Dashboard::environment('local');

    expect(access($this->getJson(ME)))->toBe([200, ['view' => true, 'manage' => true]])
        ->and(access($this->actingAs(viewer())->getJson(ME)))->toBe([200, ['view' => true, 'manage' => true]]);
});

it('AC-2: turns guests and users away in production when the app defines no gate', function (): void {
    Dashboard::environment('production');

    expect($this->getJson(ME)->status())->toBe(403)
        ->and($this->actingAs(viewer())->getJson(ME)->status())->toBe(403);
});

it('AC-3: lets the gates the app defines decide, in every environment', function (string $environment): void {
    Dashboard::environment($environment);
    definedGates();

    expect(access($this->getJson(ME)))->toBe([403, null])
        ->and(access($this->actingAs(viewer())->getJson(ME)))->toBe([200, ['view' => true, 'manage' => false]])
        ->and(access($this->actingAs(manager())->getJson(ME)))->toBe([200, ['view' => true, 'manage' => true]])
        ->and(access($this->actingAs(new TestUser(['id' => 3]))->getJson(ME)))->toBe([403, null]);
})->with(['production', 'local']);

it('AC-4: lets a manager read when the app defines only the manage gate, and turns the others away in production', function (): void {
    Dashboard::environment('production');
    Dashboard::gates(null, fn (TestUser $user): bool => $user->getAuthIdentifier() === 2);

    expect(access($this->actingAs(manager())->getJson(ME)))->toBe([200, ['view' => true, 'manage' => true]])
        ->and($this->actingAs(viewer())->getJson(ME)->status())->toBe(403);
});

it('AC-5: answers a denied JSON request with a 403 JSON message, and a denied page with a 403, never a redirect', function (): void {
    Dashboard::environment('production');

    $json = $this->getJson(ME);
    $page = $this->get(ME, ['Accept' => 'text/html']);

    expect([$json->status(), is_string($json->json('message'))])->toBe([403, true])
        ->and([$page->status(), $page->headers->has('Location')])->toBe([403, false]);
});

/*
| AC-6: the write routes.
*/

it('AC-6: needs manageDynamicThrottle on a write route, and a CSRF token, as any web route does', function (): void {
    Dashboard::environment('production');
    definedGates();
    Dashboard::writeRoute();
    $path = '/dynamic-throttle/'.Dashboard::WRITE_PATH;
    $token = ['_token' => 'a-token'];

    $viewer = $this->actingAs(viewer())->withSession($token)->postJson($path, $token);
    $managerWithoutToken = $this->actingAs(manager())->withSession($token)->postJson($path);
    $manager = $this->actingAs(manager())->withSession($token)->postJson($path, $token);

    expect([$viewer->status(), $managerWithoutToken->status(), $manager->status()])->toBe([403, 419, 200]);
});

/*
| AC-7 and AC-8: the dashboard's own limit, and its headers.
*/

it('AC-7: answers a user\'s 121st request in a minute with Laravel\'s 429, and counts each user on their own', function (): void {
    Dashboard::environment('local');

    for ($request = 0; $request < 120; $request++) {
        $this->actingAs(viewer())->getJson(ME)->assertOk();
    }

    expect($this->actingAs(viewer())->getJson(ME)->status())->toBe(429)
        ->and($this->actingAs(manager())->getJson(ME)->status())->toBe(200);
});

it('AC-7: counts guests by IP', function (): void {
    Dashboard::boot(['rate_limit' => 2]);
    Dashboard::environment('local');
    $from = fn (string $ip): int => $this->withServerVariables(['REMOTE_ADDR' => $ip])->getJson(ME)->status();

    expect([$from('198.51.100.1'), $from('198.51.100.1'), $from('198.51.100.1'), $from('198.51.100.2')])->toBe([200, 200, 429, 200]);
});

it('AC-7: sets no limit of its own with dashboard.rate_limit 0', function (): void {
    Dashboard::boot(['rate_limit' => 0]);
    Dashboard::environment('local');

    for ($request = 0; $request < 130; $request++) {
        $this->getJson(ME)->assertOk();
    }
});

it('AC-8: sends no-store and nosniff on every response of the group, a 403 and a 429 too', function (): void {
    Dashboard::boot(['rate_limit' => 1]);
    Dashboard::environment('local');
    $ok = $this->getJson(ME);
    $limited = $this->getJson(ME);
    Dashboard::environment('production');
    $denied = $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.9'])->getJson(ME);

    $headers = array_map(fn (TestResponse $response): array => [
        $response->status(),
        str_contains((string) $response->headers->get('Cache-Control'), 'no-store'),
        $response->headers->get('X-Content-Type-Options'),
    ], [$ok, $limited, $denied]);

    expect($headers)->toBe([[200, true, 'nosniff'], [429, true, 'nosniff'], [403, true, 'nosniff']]);
});
