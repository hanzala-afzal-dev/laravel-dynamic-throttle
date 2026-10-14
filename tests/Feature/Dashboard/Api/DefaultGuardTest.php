<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Exemption;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestAdmin;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Explanations;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Carbon;

/*
| SPEC-017 AC-25, from the security review: behind dashboard.middleware such as
| ['web', 'auth:admins'], Laravel's auth middleware makes the admins guard the
| default for the rest of the request. A user id alone still means a user of the
| app's own default guard, web here, in exemptions and in explain. actingAs()
| on the admins guard does what that middleware does.
*/

beforeEach(function (): void {
    DashboardApi::prepare();
    $this->travelTo(Carbon::parse('2027-01-15 12:00:00', 'UTC'));
    config()->set('auth.guards.admins', ['driver' => 'session', 'provider' => 'admins']);
    config()->set('auth.providers.admins', ['driver' => 'eloquent', 'model' => TestAdmin::class]);
    $this->actingAs(DashboardApi::manager(), 'admins');
});

afterEach(function (): void {
    ManifestRepository::flushState();
});

it('AC-25: saves a user exemption given by id as a user of the app\'s default guard, not the admin\'s', function (): void {
    $response = DashboardApi::send($this, 'POST', 'exemptions', ['subject' => 'user', 'value' => '42']);

    expect($response->status())->toBe(201)
        ->and(Exemption::query()->sole()->value)->toBe(User::class.':42');
});

it('AC-25: explains a user given by id through the app\'s default guard, not the admin\'s', function (): void {
    $rule = Throttling::rule(['name' => 'Users', 'scope' => Scope::User]);
    Throttling::route('api/orders', ['auth', 'dynamic.throttle']);
    Explanations::users(42);

    $response = DashboardApi::send($this, 'POST', 'explain', ['method' => 'GET', 'path' => '/api/orders', 'user' => '42']);

    expect([$response->status(), Explanations::rules($response->json())[(string) $rule->id]['identity'] ?? null])
        ->toBe([200, 'user:'.User::class.':42']);
});
