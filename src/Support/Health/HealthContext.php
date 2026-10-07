<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Http\Untouched;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Support\LimiterStoreFactory;
use Afzal\DynamicThrottle\Support\RouteMiddleware;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Routing\Route;
use InvalidArgumentException;
use Throwable;

/**
 * What several health checks read, read once for the report: the counter driver,
 * the manifest and the routes (SPEC-016). It only reads: the manifest as
 * dynamic-throttle:status reads it, never rebuilt, and the routes as explain reads
 * them.
 *
 * @internal
 */
final class HealthContext
{
    private string|InvalidArgumentException|null $driver = null;

    private ?ManifestRead $manifest = null;

    private ?RouteMiddleware $routes = null;

    /** @var list<array{Route, list<string>}>|null */
    private ?array $routeList = null;

    public function __construct(
        public readonly Container $container,
        public readonly Config $config,
        private readonly Clock $clock,
    ) {}

    /**
     * Unix seconds, by the package's clock.
     */
    public function now(): int
    {
        return intdiv($this->clock->microseconds(), 1_000_000);
    }

    /**
     * The counter driver as dynamic-throttle:status describes it, such as "cache, store
     * array", or why the config cannot build it (ADR-0020 point 1).
     */
    public function driver(): string|InvalidArgumentException
    {
        if ($this->driver === null) {
            try {
                $this->driver = $this->container->make(LimiterStoreFactory::class)->describe();
            } catch (InvalidArgumentException $exception) {
                $this->driver = $exception;
            }
        }

        return $this->driver;
    }

    /**
     * The manifest servers read, from the cache store, without L0 or L1, and without
     * rebuilding a missing one, as dynamic-throttle:status reads it.
     */
    public function manifest(): ManifestRead
    {
        return $this->manifest ??= $this->readManifest();
    }

    /**
     * The rules a request can match, or why they are unknown.
     *
     * @return list<CompiledRule>|string
     */
    public function rules(): array|string
    {
        $read = $this->manifest();

        return $read->manifest instanceof Manifest ? $read->manifest->rules() : (string) $read->problem();
    }

    public function routes(): RouteMiddleware
    {
        return $this->routes ??= RouteMiddleware::of($this->container);
    }

    /**
     * Every route of the app with its middleware in running order, but the package's
     * own, which are never limited (ADR-0004 point 5).
     *
     * @return list<array{Route, list<string>}>
     */
    public function routeList(): array
    {
        if ($this->routeList === null) {
            $this->routeList = [];

            foreach ($this->routes()->router()->getRoutes()->getRoutes() as $route) {
                if (! str_starts_with((string) $route->getName(), DynamicThrottleMiddleware::OWN_ROUTES)) {
                    $this->routeList[] = [$route, $this->routes()->middleware($route)];
                }
            }
        }

        return $this->routeList;
    }

    /**
     * Whether dynamic.throttle runs for the route: on the route, or as global middleware.
     *
     * @param  list<string>  $middleware  the route's
     */
    public function throttled(array $middleware): bool
    {
        return $this->routes()->global || $this->routes()->position($middleware) !== null;
    }

    /**
     * Whether except covers the route's path, so no rule applies to it (ADR-0020 point 3).
     */
    public function excepted(Route $route): bool
    {
        return Untouched::except($this->config, $route->uri()) instanceof Untouched;
    }

    /**
     * An error's message, with its root cause's when it does not already say it, as
     * explain gives it.
     */
    public static function message(Throwable $exception): string
    {
        $root = $exception;

        while (($previous = $root->getPrevious()) instanceof Throwable) {
            $root = $previous;
        }

        $message = $exception->getMessage();

        return $root === $exception || str_contains($message, $root->getMessage()) ? $message : $message.' '.$root->getMessage();
    }

    /**
     * Items for a message: the first ten, then how many more (SPEC-016's many routes).
     *
     * @param  list<string>  $items
     */
    public static function listed(array $items, string $separator = ', '): string
    {
        $shown = array_slice($items, 0, 10);
        $more = count($items) - count($shown);

        return implode($separator, $shown).($more > 0 ? "{$separator}and {$more} more" : '');
    }

    /**
     * A route as a message names it: "GET|HEAD api/users".
     */
    public static function route(Route $route): string
    {
        return implode('|', $route->methods()).' '.$route->uri();
    }

    private function readManifest(): ManifestRead
    {
        try {
            $store = $this->config->get('dynamic-throttle.store');
            $json = $this->container->make(CacheFactory::class)
                ->store(is_string($store) && $store !== '' ? $store : null)
                ->get($this->container->make(KeyBuilder::class)->manifest());
            $manifest = is_string($json) ? ManifestCodec::decode($json) : null;
        } catch (Throwable $exception) {
            return ManifestRead::unreadable(self::message($exception));
        }

        return $manifest instanceof Manifest ? ManifestRead::of($manifest) : ManifestRead::missing();
    }
}
