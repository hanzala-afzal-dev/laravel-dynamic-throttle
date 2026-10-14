<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Illuminate\Http\Request;

/*
| SPEC-017 decision 5: who an audit entry says made a change. A user is named as
| exemptions and the user scope name one, a guest that an undefined gate lets in
| locally is "guest", and the CLI keeps its own name, with no IP or user agent.
*/

covers(Actor::class);

/**
 * A dashboard request from 203.0.113.7, with a user agent.
 */
function dashboardRequest(?TestUser $user): Request
{
    $request = Request::create('/dynamic-throttle/api/settings/bypass', 'PUT', server: [
        'REMOTE_ADDR' => '203.0.113.7',
        'HTTP_USER_AGENT' => 'Mozilla/5.0 (X11; Linux x86_64)',
    ]);
    $request->setUserResolver(fn (): ?TestUser => $user);

    return $request;
}

it('names a user by class and id, with the request\'s IP and user agent', function (): void {
    $actor = Actor::of(dashboardRequest(new TestUser(['id' => 42])));

    expect([$actor->name, $actor->ip, $actor->userAgent])
        ->toBe(['user:'.TestUser::class.':42', '203.0.113.7', 'Mozilla/5.0 (X11; Linux x86_64)']);
});

it('names a guest guest', function (): void {
    $actor = Actor::of(dashboardRequest(null));

    expect([$actor->name, $actor->ip])->toBe(['guest', '203.0.113.7']);
});

it('names the CLI as SPEC-013 does, with no IP or user agent', function (): void {
    $actor = Actor::cli();

    expect([$actor->name, $actor->ip, $actor->userAgent])->toBe([Settings::cliActor(), null, null]);
});
