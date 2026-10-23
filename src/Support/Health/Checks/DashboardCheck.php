<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Dashboard\Gates;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;

/**
 * Whether anyone can open the dashboard (SPEC-033). Outside the local environment,
 * nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle
 * (ADR-0015), and the dashboard's 403 says nothing about why. It reads what Gates
 * reads: the gates the app defines, and the environment. It cannot tell whether a
 * defined gate lets anyone in, since its callback decides per user.
 *
 * @internal
 */
final readonly class DashboardCheck implements Check
{
    public function id(): string
    {
        return 'dashboard';
    }

    public function run(HealthContext $context): CheckResult
    {
        if ($context->config->get('dynamic-throttle.dashboard.enabled') === false) {
            return CheckResult::skipped($this->id(), 'The dashboard is off (dynamic-throttle.dashboard.enabled).');
        }

        if (! $context->container->make(Gates::class)->defined()) {
            return $context->container->make(Application::class)->environment('local')
                ? CheckResult::ok($this->id(), 'In the local environment, anyone can open the dashboard, guests too. Elsewhere, nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle.')
                : CheckResult::warning($this->id(), 'Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle, or turn the dashboard off with DYNAMIC_THROTTLE_DASHBOARD=false (docs/guide/dashboard.md#who-gets-in).');
        }

        $gate = $context->container->make(Gate::class);

        return match (true) {
            $gate->has(Gates::VIEW) && $gate->has(Gates::MANAGE) => CheckResult::ok($this->id(), 'viewDynamicThrottle and manageDynamicThrottle decide who opens the dashboard.'),
            $gate->has(Gates::MANAGE) => CheckResult::ok($this->id(), 'manageDynamicThrottle decides who opens the dashboard, and who changes things there.'),
            default => CheckResult::ok($this->id(), 'viewDynamicThrottle decides who opens the dashboard. Nobody can change anything there until the app defines manageDynamicThrottle.'),
        };
    }
}
