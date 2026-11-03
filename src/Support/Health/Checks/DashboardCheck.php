<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Dashboard\Gates;
use Afzal\DynamicThrottle\Dashboard\Links\Links;
use Afzal\DynamicThrottle\Support\Docs;
use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;
use Afzal\DynamicThrottle\Support\RouteMiddleware;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Session\Middleware\StartSession;

/**
 * Whether anyone can open the dashboard (SPEC-033). Outside the local environment,
 * nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle
 * (ADR-0015), and the dashboard's 403 says nothing about why. It reads what Gates
 * reads: the gates the app defines, and the environment. It cannot tell whether a
 * defined gate lets anyone in, since its callback decides per user.
 *
 * With links on (SPEC-034), links open the dashboard too: they need a session, which
 * the dashboard's middleware must start. Whether their table exists is for the
 * command and the link's page to say: a health check never reads the database
 * (SPEC-016 AC-21).
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

        $links = Links::enabled($context->config);
        $problem = $links ? $this->linksProblem($context) : null;

        if ($problem !== null) {
            return CheckResult::warning($this->id(), $problem);
        }

        if (! $context->container->make(Gates::class)->defined()) {
            return match (true) {
                $context->container->make(Application::class)->environment('local') => CheckResult::ok($this->id(), 'In the local environment, anyone can open the dashboard, guests too. Elsewhere, nobody can until the app defines viewDynamicThrottle or manageDynamicThrottle.'),
                $links => CheckResult::ok($this->id(), 'Only links from dynamic-throttle:link open the dashboard: the app defines neither viewDynamicThrottle nor manageDynamicThrottle.'),
                default => CheckResult::warning($this->id(), 'Nobody can open the dashboard: define viewDynamicThrottle or manageDynamicThrottle, or turn the dashboard off with DYNAMIC_THROTTLE_DASHBOARD=false: see '.Docs::url('guide/dashboard#who-gets-in').'.'),
            };
        }

        $gate = $context->container->make(Gate::class);
        $alsoLinks = $links ? ' Links from dynamic-throttle:link open it too.' : '';

        return CheckResult::ok($this->id(), match (true) {
            $gate->has(Gates::VIEW) && $gate->has(Gates::MANAGE) => 'viewDynamicThrottle and manageDynamicThrottle decide who opens the dashboard.',
            $gate->has(Gates::MANAGE) => 'manageDynamicThrottle decides who opens the dashboard, and who changes things there.',
            default => 'viewDynamicThrottle decides who opens the dashboard. Nobody can change anything there until the app defines manageDynamicThrottle.',
        }.$alsoLinks);
    }

    /**
     * Why links cannot let anyone in, or null: the dashboard's middleware starts no
     * session for a link to sign in to.
     */
    private function linksProblem(HealthContext $context): ?string
    {
        $routes = $context->routes();
        $route = $routes->router()->getRoutes()->getByName('dynamic-throttle.api.link')
            ?? $routes->router()->getRoutes()->getByName('dynamic-throttle.api.me');

        // No dashboard route at all: the config check says why.
        if ($route === null) {
            return null;
        }

        foreach ($routes->middleware($route) as $middleware) {
            if (is_a(RouteMiddleware::className($middleware), StartSession::class, true)) {
                return null;
            }
        }

        return "Links are on, but the dashboard's middleware starts no session, so a link cannot sign anyone in: add the web group to dynamic-throttle.dashboard.middleware.";
    }
}
