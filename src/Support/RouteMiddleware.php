<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;

/**
 * Routes' middleware as Laravel runs it, and where dynamic.throttle and
 * authentication run among them (ADR-0004, SPEC-014). dynamic-throttle:explain
 * and dynamic-throttle:health read routes through it, so the two never disagree
 * (SPEC-016 decision 10).
 *
 * @internal
 */
final readonly class RouteMiddleware
{
    private function __construct(
        private Router $router,
        private Config $config,
        /** Whether the app runs dynamic.throttle as global middleware, before routing. */
        public bool $global,
    ) {}

    /**
     * The HTTP kernel is resolved first, so the router holds the app's groups, aliases
     * and priority list, as for a web request.
     */
    public static function of(Container $container): self
    {
        $global = self::isGlobal($container->make(HttpKernel::class));

        return new self($container->make(Router::class), $container->make('config'), $global);
    }

    public function router(): Router
    {
        return $this->router;
    }

    /**
     * A route's middleware in the order they run, aliases as their classes, with their
     * parameters, such as "Illuminate\Routing\Middleware\ThrottleRequests:60,1".
     * Reading a controller's middleware builds the controller, as route:list does.
     *
     * @return list<string>
     */
    public function middleware(Route $route): array
    {
        return array_map(
            fn (mixed $middleware): string => is_string($middleware) ? $middleware : get_debug_type($middleware),
            array_values($this->router->gatherRouteMiddleware($route)),
        );
    }

    /**
     * Where dynamic.throttle runs among a route's middleware, or null.
     *
     * @param  list<string>  $middleware
     */
    public function position(array $middleware): ?int
    {
        foreach ($middleware as $position => $name) {
            if (self::className($name) === DynamicThrottleMiddleware::class) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Whether one of the middleware logs users in: Laravel's authentication, its basic
     * authentication, or one listed in middleware.after (ADR-0004 point 2).
     *
     * @param  list<string>  $middleware
     */
    public function authenticates(array $middleware): bool
    {
        $after = $this->after();

        foreach ($middleware as $name) {
            $class = self::className($name);

            if (is_a($class, AuthenticatesRequests::class, true) || is_a($class, AuthenticateWithBasicAuth::class, true) || in_array($class, $after, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether the app runs dynamic.throttle as global middleware. Another kernel than
     * Laravel's has no list to ask.
     */
    private static function isGlobal(HttpKernel $kernel): bool
    {
        return $kernel instanceof Kernel && $kernel->hasMiddleware(DynamicThrottleMiddleware::class);
    }

    /**
     * A middleware's class, without its parameters.
     */
    public static function className(string $middleware): string
    {
        return explode(':', $middleware, 2)[0];
    }

    /**
     * middleware.after, aliases as their classes.
     *
     * @return list<string>
     */
    private function after(): array
    {
        $aliases = $this->router->getMiddleware();
        $after = [];

        foreach ((array) $this->config->get('dynamic-throttle.middleware.after') as $name) {
            if (is_string($name) && $name !== '') {
                $name = self::className($name);
                $after[] = is_string($aliases[$name] ?? null) ? $aliases[$name] : $name;
            }
        }

        return $after;
    }
}
