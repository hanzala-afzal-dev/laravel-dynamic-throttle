<?php

declare(strict_types=1);

use Afzal\DynamicThrottle\Dashboard\Links\LinkToken;
use Afzal\DynamicThrottle\Models\DashboardLink;
use Afzal\DynamicThrottle\Support\Changes\Actor;
use Afzal\DynamicThrottle\Support\EmergencyControls;
use Afzal\DynamicThrottle\Tests\Support\Dashboard;
use Afzal\DynamicThrottle\Tests\Support\DashboardApi;
use Afzal\DynamicThrottle\Tests\Support\Settings;
use Afzal\DynamicThrottle\Tests\Support\Throttling;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;

/*
| SPEC-034 AC-11 to AC-20: using a link (ADR-0031). Links are on, the app runs in
| production with no gate defined, so only a link lets anyone in, and the clock
| stands at 2027-01-01 00:00:00 UTC. The tests' session lasts from one request to
| the next, as a browser's does.
*/

const LINK_NOT_VALID = 'This link is not valid: it was already used, it was revoked, or it has expired. Ask for a new one.';

beforeEach(function (): void {
    Throttling::prepare();
    Dashboard::boot(['links' => true]);
    Dashboard::environment('production');
    Dashboard::fixtureBuild();
    $this->travelTo(Carbon::parse('2027-01-01 00:00:00', 'UTC'));
});

/**
 * A link, made as dynamic-throttle:link makes one, and its token.
 *
 * @return array{0: DashboardLink, 1: string}
 */
function dashboardLink(string $name = 'alice, incident 42', bool $manage = false, int $minutes = 60): array
{
    $token = LinkToken::make();
    $link = app(EmergencyControls::class)->createLink($name, LinkToken::hash($token), $manage, Date::now()->addMinutes($minutes), Actor::cli());

    return [$link, $token];
}

/**
 * Presses "Continue": posts the token, with the session's CSRF token.
 *
 * @return TestResponse<Response>
 */
function useDashboardLink(TestCase $test, mixed $token): TestResponse
{
    return DashboardApi::send($test, 'POST', 'link', ['token' => $token]);
}

function linkEntries(string $action): int
{
    return count(array_filter(Settings::audit(), fn (array $entry): bool => $entry['action'] === $action));
}

it('AC-11: serves the link\'s page to a guest, with no gate, with the dashboard\'s headers, and reads or changes no link', function (): void {
    [$link] = dashboardLink();

    $page = $this->get('/dynamic-throttle/link');

    expect($page->status())->toBe(200)
        ->and($page->getContent())->toContain('id="dynamic-throttle"')
        ->and($page->headers->get('Content-Security-Policy'))->toContain("script-src 'self'")
        ->and($page->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($page->headers->get('Cache-Control'))->toContain('no-store')
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at)->toBeNull()
        ->and(linkEntries('link.use'))->toBe(0)
        // The dashboard's other pages still need a gate.
        ->and($this->get('/dynamic-throttle/rules')->status())->toBe(403);
});

it('AC-11: has neither link route while links are off', function (): void {
    Dashboard::boot(['links' => false]);
    Dashboard::fixtureBuild();
    [, $token] = dashboardLink();

    expect($this->get('/dynamic-throttle/link')->status())->toBe(404)
        ->and(useDashboardLink($this, $token)->status())->toBe(404);
});

it('AC-12: uses a valid link: 204, marks it used, and lets the session view, not change', function (): void {
    Dashboard::writeRoute();
    [$link, $token] = dashboardLink();

    $use = useDashboardLink($this, $token);
    $me = DashboardApi::send($this, 'GET', 'me');
    $read = DashboardApi::send($this, 'GET', 'rules');
    $write = DashboardApi::sendTo($this, 'POST', '/dynamic-throttle/'.Dashboard::WRITE_PATH);

    expect($use->status())->toBe(204)
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at?->toIso8601String())->toBe('2027-01-01T00:00:00+00:00')
        ->and(session()->get('dynamic-throttle.link'))->toBe($link->id)
        ->and($me->status())->toBe(200)
        ->and($me->json('can'))->toBe(['view' => true, 'manage' => false])
        ->and($me->json('link'))->toBe(['name' => 'alice, incident 42', 'manage' => false, 'expires_at' => '2027-01-01T01:00:00+00:00'])
        ->and($read->status())->toBe(200)
        ->and($write->status())->toBe(403);
});

it('AC-12: writes one link.use entry, with the link as the actor and the request\'s IP and user agent', function (): void {
    [$link, $token] = dashboardLink();

    useDashboardLink($this, $token);

    expect(Settings::audit()[1])->toBe([
        'actor' => "link:{$link->id}:alice, incident 42",
        'action' => 'link.use',
        'target' => "link:{$link->id}",
        'before' => ['used_at' => null],
        'after' => ['used_at' => '2027-01-01T00:00:00+00:00'],
        'ip' => '127.0.0.1',
        'user_agent' => DashboardApi::USER_AGENT,
    ]);
});

it('AC-12: gives the session a new id when the link is used', function (): void {
    [, $token] = dashboardLink();
    $cookie = (string) config('session.cookie');

    $page = $this->get('/dynamic-throttle/link');
    $before = $page->getCookie($cookie)?->getValue();
    $use = $this->withCookie($cookie, (string) $before)
        ->withHeaders(['X-CSRF-TOKEN' => session()->token()])
        ->postJson('/dynamic-throttle/api/link', ['token' => $token]);

    expect($use->status())->toBe(204)
        ->and($before)->toBeString()
        ->and($use->getCookie($cookie)?->getValue())->toBeString()->not->toBe($before);
});

it('AC-13: refuses a token used once already, from this session or another, and changes nothing', function (): void {
    [$link, $token] = dashboardLink();
    useDashboardLink($this, $token);
    $usedAt = DashboardLink::query()->findOrFail($link->id)->used_at;

    $again = useDashboardLink($this, $token);
    $this->flushSession();
    $elsewhere = useDashboardLink($this, $token);

    expect([$again->status(), $elsewhere->status()])->toBe([403, 403])
        ->and([$again->json('message'), $elsewhere->json('message')])->toBe([LINK_NOT_VALID, LINK_NOT_VALID])
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at?->equalTo($usedAt))->toBeTrue()
        ->and(linkEntries('link.use'))->toBe(1)
        ->and(session()->has('dynamic-throttle.link'))->toBeFalse();
});

it('AC-13: refuses a revoked link, an expired one and a token no link has, with one message, and changes nothing', function (string $case): void {
    [$link, $token] = dashboardLink(minutes: 30);

    match ($case) {
        'revoked' => app(EmergencyControls::class)->revokeLink($link, Actor::cli()),
        'expired' => $this->travel(30)->minutes(),
        'unknown' => $token = LinkToken::make(),
    };

    $use = useDashboardLink($this, $token);

    expect($use->status())->toBe(403)
        ->and($use->json('message'))->toBe(LINK_NOT_VALID)
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at)->toBeNull()
        ->and(linkEntries('link.use'))->toBe(0)
        ->and(session()->has('dynamic-throttle.link'))->toBeFalse()
        ->and(DashboardApi::send($this, 'GET', 'me')->status())->toBe(403);
})->with(['revoked', 'expired', 'unknown']);

it('AC-13: answers 422 for a missing token, or one that is not 43 base64url characters', function (mixed $token): void {
    [$link] = dashboardLink();

    $use = $token === 'missing' ? DashboardApi::send($this, 'POST', 'link') : useDashboardLink($this, $token);

    expect($use->status())->toBe(422)
        ->and($use->json('errors'))->toHaveKey('token')
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at)->toBeNull();
})->with([
    'missing' => ['missing'],
    'empty' => [''],
    'too short' => [str_repeat('a', 42)],
    'too long' => [str_repeat('a', 44)],
    'not base64url' => [str_repeat('a', 42).'+'],
    'not text' => [['a']],
]);

it('AC-14: lets exactly one of two uses of the same token through, with one link.use entry', function (): void {
    [$link, $token] = dashboardLink();

    $first = useDashboardLink($this, $token);
    $this->flushSession();
    $second = useDashboardLink($this, $token);

    expect([$first->status(), $second->status()])->toBe([204, 403])
        ->and(linkEntries('link.use'))->toBe(1);
});

it('AC-14: refuses a link that another request used after the token was checked', function (): void {
    [$link, $token] = dashboardLink();
    // Another request's use, committed just before this one's: the use is one update, guarded by used_at.
    DashboardLink::query()->whereKey($link->id)->update(['used_at' => '2027-01-01 00:00:00']);

    expect(useDashboardLink($this, $token)->status())->toBe(403)
        ->and(linkEntries('link.use'))->toBe(0);
});

it('AC-15: lets a --manage link\'s session change what a manager can, with the link as the actor', function (): void {
    [$link, $token] = dashboardLink(manage: true);
    useDashboardLink($this, $token);

    $me = DashboardApi::send($this, 'GET', 'me');
    $bypass = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '1h', 'confirm' => true]);
    $entry = collect(Settings::audit())->firstWhere('action', 'settings.bypass');

    expect($me->json('can'))->toBe(['view' => true, 'manage' => true])
        ->and($me->json('link.manage'))->toBeTrue()
        ->and($bypass->status())->toBe(200)
        ->and([$entry['actor'] ?? null, $entry['ip'] ?? null, $entry['user_agent'] ?? null])
        ->toBe(["link:{$link->id}:alice, incident 42", '127.0.0.1', DashboardApi::USER_AGENT]);
});

it('AC-15: still asks a --manage link\'s session to confirm a dangerous change', function (): void {
    [, $token] = dashboardLink(manage: true);
    useDashboardLink($this, $token);

    expect(DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '1h'])->status())->toBe(409);
});

it('AC-16: ends the session when its link ends, at its end time', function (): void {
    [, $token] = dashboardLink(minutes: 30);
    useDashboardLink($this, $token);

    $before = DashboardApi::send($this, 'GET', 'me')->status();
    $this->travel(30)->minutes();
    $at = DashboardApi::send($this, 'GET', 'me')->status();

    expect([$before, $at])->toBe([200, 403]);
});

it('AC-16: ends the session at its next request once its link is revoked', function (): void {
    [$link, $token] = dashboardLink();
    useDashboardLink($this, $token);
    $before = DashboardApi::send($this, 'GET', 'me')->status();

    $this->artisan('dynamic-throttle:link', ['--revoke' => (string) $link->id])->assertExitCode(0)->run();

    expect([$before, DashboardApi::send($this, 'GET', 'me')->status()])->toBe([200, 403]);
});

it('AC-16: ignores the session\'s link once links are turned off', function (): void {
    [, $token] = dashboardLink();
    useDashboardLink($this, $token);
    $before = DashboardApi::send($this, 'GET', 'me')->status();

    config()->set('dynamic-throttle.dashboard.links', false);

    expect([$before, DashboardApi::send($this, 'GET', 'me')->status()])->toBe([200, 403]);
});

it('AC-17: lets a signed-in viewer holding a --manage link change things, with the link as the actor', function (): void {
    Dashboard::gates(
        fn (?object $user): bool => $user?->getAuthIdentifier() === 1,
        fn (?object $user): bool => $user?->getAuthIdentifier() === 2,
    );
    [$link, $token] = dashboardLink(manage: true);

    useDashboardLink($this, $token);
    $me = DashboardApi::send($this, 'GET', 'me', user: DashboardApi::viewer());
    $bypass = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '1h', 'confirm' => true], DashboardApi::viewer());

    expect($me->json('can'))->toBe(['view' => true, 'manage' => true])
        ->and($bypass->status())->toBe(200)
        ->and(collect(Settings::audit())->firstWhere('action', 'settings.bypass')['actor'] ?? null)->toBe("link:{$link->id}:alice, incident 42");
});

it('AC-17: keeps a signed-in manager holding a view link as the actor of their changes', function (): void {
    Dashboard::gates(
        fn (?object $user): bool => $user?->getAuthIdentifier() === 1,
        fn (?object $user): bool => $user?->getAuthIdentifier() === 2,
    );
    [, $token] = dashboardLink();

    useDashboardLink($this, $token);
    $bypass = DashboardApi::send($this, 'PUT', 'settings/bypass', ['state' => 'on', 'for' => '1h', 'confirm' => true], DashboardApi::manager());

    expect($bypass->status())->toBe(200)
        ->and(collect(Settings::audit())->firstWhere('action', 'settings.bypass')['actor'] ?? null)->toBe(DashboardApi::managerActor());
});

it('AC-17: changes nothing in the local environment with no gate, where everyone gets in', function (): void {
    Dashboard::environment('local');

    $me = DashboardApi::send($this, 'GET', 'me');

    expect($me->status())->toBe(200)
        ->and($me->json('can'))->toBe(['view' => true, 'manage' => true])
        ->and($me->json('link'))->toBeNull();
});

it('AC-18: refuses a use without the session\'s CSRF token, as any web route does', function (): void {
    [$link, $token] = dashboardLink();

    $use = $this->withSession(['_token' => 'a-csrf-token'])->postJson('/dynamic-throttle/api/link', ['token' => $token]);

    expect($use->status())->toBe(419)
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at)->toBeNull();
});

it('AC-19: keeps the token and its hash out of the session and the audit log', function (): void {
    [, $token] = dashboardLink();

    useDashboardLink($this, $token);
    $stored = serialize(session()->all()).json_encode(Settings::audit());

    expect($stored)->not->toContain($token)->not->toContain(LinkToken::hash($token));
});

it('AC-19: never uses a token from the address, in a query or a path', function (): void {
    [$link, $token] = dashboardLink();

    $query = $this->get('/dynamic-throttle/link?token='.$token);
    $path = $this->get('/dynamic-throttle/link/'.$token);
    $post = DashboardApi::sendTo($this, 'POST', '/dynamic-throttle/api/link?token='.$token);

    expect([$query->status(), $path->status(), $post->status()])->toBe([200, 404, 422])
        ->and(DashboardLink::query()->findOrFail($link->id)->used_at)->toBeNull();
});

it('AC-20: limits uses to the dashboard\'s 120 requests a minute per IP', function (): void {
    $statuses = [];

    for ($i = 1; $i <= 121; $i++) {
        $statuses[] = useDashboardLink($this, LinkToken::make())->status();
    }

    expect(array_count_values($statuses))->toBe([403 => 120, 429 => 1])
        ->and(end($statuses))->toBe(429);
});

it('AC-20: never limits the link\'s routes by a "*" rule', function (): void {
    config()->set('dynamic-throttle.middleware.auto_attach', ['web']);
    Throttling::rule(['pattern' => '*', 'max_attempts' => 1]);
    Dashboard::boot(['links' => true]);
    Dashboard::fixtureBuild();
    Route::middleware('web')->get('orders', fn (): string => 'ok');

    $page = [$this->get('/dynamic-throttle/link')->status(), $this->get('/dynamic-throttle/link')->status(), $this->get('/dynamic-throttle/link')->status()];
    $uses = [useDashboardLink($this, LinkToken::make())->status(), useDashboardLink($this, LinkToken::make())->status()];
    $orders = [$this->get('/orders')->status(), $this->get('/orders')->status()];

    expect($page)->toBe([200, 200, 200])
        ->and($uses)->toBe([403, 403])
        ->and($orders)->toBe([200, 429]);
});
