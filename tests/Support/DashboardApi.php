<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Tests\Support\Auth\TestUser;
use Afzal\DynamicThrottle\Tests\TestCase;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * The dashboard's JSON API, as SPEC-017's tests call it: in production, with the
 * gates an app defines (user 1 may view, user 2 may manage, guests neither), and
 * requests sent as the dashboard's script sends them.
 */
final class DashboardApi
{
    /** Where the API lives with the default dashboard path. */
    public const string PREFIX = '/dynamic-throttle/api/';

    /** The user agent each request sends, which the audit log records. */
    public const string USER_AGENT = 'Pest dashboard script';

    /** The session's CSRF token, which each change sends back in X-CSRF-TOKEN. */
    private const string TOKEN = 'a-csrf-token';

    /**
     * The package's tables, the dashboard's routes, production and the gates.
     */
    public static function prepare(): void
    {
        Throttling::prepare();
        Dashboard::boot();
        Dashboard::environment('production');
        Dashboard::gates(
            fn (TestUser $user): bool => $user->getAuthIdentifier() === 1,
            fn (TestUser $user): bool => $user->getAuthIdentifier() === 2,
        );
    }

    public static function viewer(): TestUser
    {
        return new TestUser(['id' => 1]);
    }

    public static function manager(): TestUser
    {
        return new TestUser(['id' => 2]);
    }

    /**
     * The manager's actor in the audit log.
     */
    public static function managerActor(): string
    {
        return 'user:'.TestUser::class.':2';
    }

    /**
     * Sends a JSON request, with the session's CSRF token and the user agent, as the
     * given user, or as a guest.
     *
     * @param  string  $uri  after the API's prefix, such as settings/bypass
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function send(TestCase $test, string $method, string $uri, array $data = [], ?Authenticatable $user = null, array $headers = []): TestResponse
    {
        return self::sendTo($test, $method, self::PREFIX.$uri, $data, $user, $headers);
    }

    /**
     * As send(), to any path, such as a page or an asset of the dashboard (SPEC-020).
     *
     * @param  string  $path  from the site's root, such as /dynamic-throttle/rules
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public static function sendTo(TestCase $test, string $method, string $path, array $data = [], ?Authenticatable $user = null, array $headers = []): TestResponse
    {
        if ($user instanceof Authenticatable) {
            $test->actingAs($user);
        }

        return $test->withSession(['_token' => self::TOKEN])
            ->withHeaders(['X-CSRF-TOKEN' => self::TOKEN, 'User-Agent' => self::USER_AGENT, ...$headers])
            ->json($method, $path, $data);
    }

    /**
     * Sends a request that does not ask for JSON, as a plain form would.
     *
     * @param  array<string, mixed>  $data
     * @return TestResponse<Response>
     */
    public static function sendHtml(TestCase $test, string $method, string $uri, array $data = [], ?Authenticatable $user = null): TestResponse
    {
        if ($user instanceof Authenticatable) {
            $test->actingAs($user);
        }

        // Through Laravel's verb helpers: call() alone leaves out the headers withHeaders() sets.
        $test->withSession(['_token' => self::TOKEN])
            ->withHeaders(['X-CSRF-TOKEN' => self::TOKEN, 'User-Agent' => self::USER_AGENT, 'Accept' => 'text/html']);

        return match (strtoupper($method)) {
            'POST' => $test->post(self::PREFIX.$uri, $data),
            'PUT' => $test->put(self::PREFIX.$uri, $data),
            'PATCH' => $test->patch(self::PREFIX.$uri, $data),
            'DELETE' => $test->delete(self::PREFIX.$uri, $data),
            default => $test->get(self::PREFIX.$uri),
        };
    }
}
