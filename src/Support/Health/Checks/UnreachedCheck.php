<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * Rules that match routes of the app, none of which limits them, so they never
 * apply (SPEC-035, issue #149): the routes do not run dynamic.throttle, or except
 * covers their paths (ADR-0020). Each route goes through the middleware's matcher
 * with its parameters as written, so a rule's wildcards match them (decision 3).
 * A rule that matches no route is not named (decision 5).
 *
 * @internal
 */
final readonly class UnreachedCheck implements Check
{
    public function id(): string
    {
        return 'unreached';
    }

    public function run(HealthContext $context): CheckResult
    {
        $read = $context->manifest();

        if (! $read->manifest instanceof Manifest) {
            return CheckResult::skipped($this->id(), (string) $read->problem());
        }

        if ($read->manifest->rules() === []) {
            return CheckResult::ok($this->id(), 'There is no rule.');
        }

        $routes = $context->routeList();

        if (! $context->routes()->global && array_filter($routes, fn (array $route): bool => $context->routes()->position($route[1]) !== null) === []) {
            return CheckResult::skipped($this->id(), 'No route runs dynamic.throttle: see the attached check.');
        }

        $matcher = new RuleMatcher;
        /** @var array<string, list<string>> $matched the routes each rule matches, by rule id */
        $matched = [];
        /** @var array<string, true> $limited the rules a route that matches them limits, by id */
        $limited = [];

        foreach ($routes as [$route, $middleware]) {
            $limits = $context->throttled($middleware) && ! $context->excepted($route);

            foreach ($this->paths($route) as $path) {
                foreach ($route->methods() as $method) {
                    foreach ($matcher->match($read->manifest, $method, $path)->rules as $rule) {
                        $matched[$rule->id][HealthContext::route($route)] = HealthContext::route($route);

                        if ($limits) {
                            $limited[$rule->id] = true;
                        }
                    }
                }
            }
        }

        $unreached = [];

        foreach ($read->manifest->rules() as $rule) {
            if (isset($matched[$rule->id]) && ! isset($limited[$rule->id])) {
                $unreached[] = HealthContext::rule($rule).', on '.HealthContext::listed(array_values($matched[$rule->id]));
            }
        }

        if ($unreached === []) {
            return CheckResult::ok($this->id(), 'Every rule that matches a route of the app matches one that dynamic.throttle limits.');
        }

        $count = count($unreached);

        return CheckResult::warning($this->id(), number_format($count).' '.Str::plural('rule', $count).' '.($count === 1 ? 'matches' : 'match')
            .' only routes that do not run dynamic.throttle, or that except covers, so '.($count === 1 ? 'it never applies' : 'they never apply').': '
            .HealthContext::listed($unreached, '; ')
            .'. Attach dynamic.throttle to those routes\' groups, such as web for login and sign-up forms, or list the groups in dynamic-throttle.middleware.auto_attach.');
    }

    /**
     * The paths a request to the route can have, as the matcher sees them: its URI with
     * each parameter as written, which a rule's wildcard matches, and once more without
     * its optional parameters (decision 3).
     *
     * @return list<string>
     */
    private function paths(Route $route): array
    {
        $uri = $route->uri();
        $required = (string) preg_replace('~\{(\w+)\?\}~', '{$1}', $uri);
        $without = (string) preg_replace('~/?\{\w+\?\}~', '', $uri);

        return array_values(array_unique([$required, $without === '' ? '/' : $without]));
    }
}
