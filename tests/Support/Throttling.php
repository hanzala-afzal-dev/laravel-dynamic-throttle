<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\DynamicThrottleServiceProvider;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Models\Rule;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rules, routes and responses for the SPEC-010 middleware tests.
 */
final class Throttling
{
    /** The rate-limit headers the middleware sets, in the order headers() lists them. */
    public const array HEADERS = ['X-RateLimit-Limit', 'X-RateLimit-Remaining', 'Retry-After', 'X-RateLimit-Reset'];

    /**
     * What every middleware test starts from:
     * - keyed hashing, as in any Laravel app (ADR-0024);
     * - the package's tables;
     * - nothing in the process's memory;
     * - the manifest read from the cache on every request, as on PHP-FPM, so a rule
     *   saved during a test applies to the next request, even with the clock frozen.
     */
    public static function prepare(): void
    {
        config()->set('app.key', 'base64:3u4DMqUd5sEWtS4w9UqJ7Qw/qMCRnGFhqRuSN40wNJA=');
        config()->set('dynamic-throttle.manifest.refresh_seconds', 0);
        ManifestRepository::flushState();
        RuleSchema::migrate();
    }

    /**
     * Saves a rule, which compiles the manifest. By default: api/* for every method,
     * 60 requests per minute for each IP.
     *
     * @param  array<string, mixed>  $overrides
     */
    public static function rule(array $overrides = []): Rule
    {
        return Rule::create(RuleSchema::attributes($overrides));
    }

    /**
     * A GET route that answers "ok" through the given middleware.
     *
     * @param  string|list<string>  $middleware
     */
    public static function route(string $uri, string|array $middleware = 'dynamic.throttle'): Route
    {
        return Router::middleware($middleware)->get($uri, fn (): string => 'ok');
    }

    /**
     * Sets config the provider reads at boot, then registers the provider again, as
     * an app that boots with that config does.
     *
     * @param  array<string, mixed>  $config
     */
    public static function bootWith(array $config): void
    {
        config()->set($config);
        app()->register(DynamicThrottleServiceProvider::class, force: true);
    }

    /**
     * The rate-limit headers a response carries, by name, in HEADERS order.
     *
     * @param  TestResponse<Response>  $response
     * @return array<string, string>
     */
    public static function headers(TestResponse $response): array
    {
        $headers = [];

        foreach (self::HEADERS as $name) {
            if ($response->headers->has($name)) {
                $headers[$name] = (string) $response->headers->get($name);
            }
        }

        return $headers;
    }
}
