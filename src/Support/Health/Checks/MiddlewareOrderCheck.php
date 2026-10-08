<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Support\ConfigValues;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Support\Str;

/**
 * Routes where authentication runs after dynamic.throttle, so user rules count
 * their users as guests (SPEC-016 AC-11, ADR-0004 point 6). Global middleware
 * runs before every route's, so authentication on any route runs after it. Only
 * when a user rule exists, and scopes.user.guards does not look the user up.
 *
 * @internal
 */
final readonly class MiddlewareOrderCheck implements Check
{
    public function id(): string
    {
        return 'middleware-order';
    }

    public function run(HealthContext $context): CheckResult
    {
        if (ConfigValues::guards($context->config->get('dynamic-throttle.scopes.user.guards')) !== null) {
            return CheckResult::ok($this->id(), 'dynamic-throttle.scopes.user.guards looks the user up, whatever runs before dynamic.throttle.');
        }

        $rules = $context->rules();

        if (is_string($rules)) {
            return CheckResult::skipped($this->id(), $rules);
        }

        if (array_filter($rules, fn (CompiledRule $rule): bool => $rule->scope === Scope::User) === []) {
            return CheckResult::ok($this->id(), 'There is no user rule, so the order of the middleware does not matter.');
        }

        $late = [];

        foreach ($context->routeList() as [$route, $middleware]) {
            if (! $context->excepted($route) && $context->routes()->authenticates($this->after($context, $middleware))) {
                $late[] = HealthContext::route($route);
            }
        }

        if ($late === []) {
            return CheckResult::ok($this->id(), 'Authentication runs before dynamic.throttle wherever both run.');
        }

        $count = count($late);

        return CheckResult::warning($this->id(), 'User rules count users as guests on '.number_format($count).' '.Str::plural('route', $count).' where authentication runs after dynamic.throttle: '.HealthContext::listed($late)
            .'. Attach dynamic.throttle to their route groups, with dynamic-throttle.middleware.auto_attach, rather than as global middleware, or list the guards in dynamic-throttle.scopes.user.guards.');
    }

    /**
     * The route's middleware that run after dynamic.throttle: all of them after the
     * global one, and those after its place on the route.
     *
     * @param  list<string>  $middleware
     * @return list<string>
     */
    private function after(HealthContext $context, array $middleware): array
    {
        if ($context->routes()->global) {
            return $middleware;
        }

        $position = $context->routes()->position($middleware);

        return $position === null ? [] : array_slice($middleware, $position + 1);
    }
}
