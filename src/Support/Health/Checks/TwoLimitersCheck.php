<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\RouteMiddleware;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Str;

/**
 * Routes that run both Laravel's throttle and dynamic.throttle, which both write
 * the rate-limit headers, so the one that runs last wins (SPEC-016 AC-10,
 * ADR-0020 point 9). A route under except is not limited, so it is not one.
 *
 * @internal
 */
final readonly class TwoLimitersCheck implements Check
{
    public function id(): string
    {
        return 'two-limiters';
    }

    public function run(HealthContext $context): CheckResult
    {
        $both = [];

        foreach ($context->routeList() as [$route, $middleware]) {
            if ($context->throttled($middleware) && $this->throttles($middleware) && ! $context->excepted($route)) {
                $both[] = HealthContext::route($route);
            }
        }

        if ($both === []) {
            return CheckResult::ok($this->id(), 'No route runs both Laravel\'s throttle and dynamic.throttle.');
        }

        $count = count($both);

        return CheckResult::warning($this->id(), number_format($count).' '.Str::plural('route', $count).' run both Laravel\'s throttle and dynamic.throttle, which both write the rate-limit headers, so the one that runs last wins: '
            .HealthContext::listed($both).'. Keep one limiter on each.');
    }

    /**
     * Whether Laravel's throttle runs among a route's middleware, its Redis variant included.
     *
     * @param  list<string>  $middleware
     */
    private function throttles(array $middleware): bool
    {
        foreach ($middleware as $name) {
            if (is_a(RouteMiddleware::className($name), ThrottleRequests::class, true)) {
                return true;
            }
        }

        return false;
    }
}
