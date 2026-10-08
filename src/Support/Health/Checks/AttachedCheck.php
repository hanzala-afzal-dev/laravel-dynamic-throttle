<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Support\Str;

/**
 * Whether dynamic.throttle runs anywhere (SPEC-016 AC-8, decision 13): the
 * package attaches it nowhere by itself (ADR-0020 point 11), so an app that
 * forgets to attach it limits nothing, silently.
 *
 * @internal
 */
final readonly class AttachedCheck implements Check
{
    public function id(): string
    {
        return 'attached';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->routes()->global) {
            return CheckResult::ok($this->id(), 'dynamic.throttle runs as global middleware, on every request.');
        }

        $attached = count(array_filter($context->routeList(), fn (array $route): bool => $context->routes()->position($route[1]) !== null));

        return $attached > 0
            ? CheckResult::ok($this->id(), number_format($attached).' '.Str::plural('route', $attached).' run dynamic.throttle.')
            : CheckResult::warning($this->id(), 'No route runs dynamic.throttle, and it is not global middleware, so no rule applies. Attach it to your route groups, or list them in dynamic-throttle.middleware.auto_attach.');
    }
}
