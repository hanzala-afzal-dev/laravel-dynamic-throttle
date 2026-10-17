<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Dashboard;

use Afzal\DynamicThrottle\Dashboard\Api\AuditController;
use Afzal\DynamicThrottle\Dashboard\Api\ConfigController;
use Afzal\DynamicThrottle\Dashboard\Api\ExemptionController;
use Afzal\DynamicThrottle\Dashboard\Api\ExplainController;
use Afzal\DynamicThrottle\Dashboard\Api\HealthController;
use Afzal\DynamicThrottle\Dashboard\Api\MetricsController;
use Afzal\DynamicThrottle\Dashboard\Api\OffendersController;
use Afzal\DynamicThrottle\Dashboard\Api\RuleController;
use Afzal\DynamicThrottle\Dashboard\Api\SettingController;
use Afzal\DynamicThrottle\Http\DashboardPath;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The dashboard's route group (SPEC-018): at dashboard.path, on dashboard.domain,
 * behind dashboard.middleware, then its headers, Laravel's own throttle and the
 * gates; and its build, outside the group (SPEC-020). Its routes are named dynamic-throttle.*, which no rule ever applies to
 * (ADR-0004 point 5).
 *
 * @internal
 */
final class DashboardRoutes
{
    /** Laravel's named limiter that protects the dashboard, never the package's own (ADR-0015 point 5). */
    public const string LIMITER = 'dynamic-throttle';

    public static function register(Router $router, Config $config): void
    {
        $path = DashboardPath::of($config);

        if ($path === null) {
            return;
        }

        $middleware = $config->get('dynamic-throttle.dashboard.middleware');
        $domain = $config->get('dynamic-throttle.dashboard.domain');

        $domain = is_string($domain) && $domain !== '' ? $domain : null;

        // The build, outside dashboard.middleware: no gate and no session, so it caches (SPEC-020 decision 2).
        $router->group(array_filter([
            'prefix' => $path,
            'domain' => $domain,
            'as' => DynamicThrottleMiddleware::OWN_ROUTES,
        ]), function (Router $router): void {
            $router->name('asset')->get('assets/{file}', AssetController::class)->where('file', '[A-Za-z0-9._-]+');
        });

        $router->group(array_filter([
            'prefix' => $path,
            'domain' => $domain,
            'middleware' => is_array($middleware) ? array_values(array_filter($middleware, is_string(...))) : ['web'],
            'as' => DynamicThrottleMiddleware::OWN_ROUTES,
        ]), function (Router $router): void {
            // Named before it is added, so the router's name lookups know it at once.
            $router->name('api.me')->middleware(self::middleware('read'))->get('api/me', MeController::class);

            $router->name('api.rules.index')->middleware(self::middleware('read'))->get('api/rules', [RuleController::class, 'index']);
            $router->name('api.rules.store')->middleware(self::middleware('write'))->post('api/rules', [RuleController::class, 'store']);
            $router->name('api.rules.counts')->middleware(self::middleware('read'))->get('api/rules/counts', [RuleController::class, 'counts']);
            $router->name('api.rules.show')->middleware(self::middleware('read'))->get('api/rules/{rule}', [RuleController::class, 'show'])->whereNumber('rule');
            $router->name('api.rules.update')->middleware(self::middleware('write'))->patch('api/rules/{rule}', [RuleController::class, 'update'])->whereNumber('rule');
            $router->name('api.rules.destroy')->middleware(self::middleware('write'))->delete('api/rules/{rule}', [RuleController::class, 'destroy'])->whereNumber('rule');

            $router->name('api.rules.offenders')->middleware(self::middleware('read'))->get('api/rules/{rule}/offenders', OffendersController::class)->whereNumber('rule');
            $router->name('api.metrics')->middleware(self::middleware('read'))->get('api/metrics', MetricsController::class);
            $router->name('api.health')->middleware(self::middleware('read'))->get('api/health', HealthController::class);
            $router->name('api.config')->middleware(self::middleware('read'))->get('api/config', ConfigController::class);
            $router->name('api.audit')->middleware(self::middleware('read'))->get('api/audit', AuditController::class);
            $router->name('api.explain')->middleware(self::middleware('inspect'))->post('api/explain', ExplainController::class);

            // Last, after the API: the shell for each page's path (SPEC-020).
            $router->name('page')->middleware([...self::middleware('read'), ContentSecurityPolicy::class])
                ->get('{view?}', PageController::class)->where('view', PageController::PAGES);

            $router->name('api.exemptions.index')->middleware(self::middleware('read'))->get('api/exemptions', [ExemptionController::class, 'index']);
            $router->name('api.exemptions.store')->middleware(self::middleware('write'))->post('api/exemptions', [ExemptionController::class, 'store']);
            $router->name('api.exemptions.destroy')->middleware(self::middleware('write'))->delete('api/exemptions/{exemption}', [ExemptionController::class, 'destroy'])->whereNumber('exemption');

            $router->name('api.settings')->middleware(self::middleware('read'))->get('api/settings', [SettingController::class, 'show']);
            $router->name('api.settings.bypass')->middleware(self::middleware('write'))->put('api/settings/bypass', [SettingController::class, 'bypass']);
            $router->name('api.settings.scale')->middleware(self::middleware('write'))->put('api/settings/scale', [SettingController::class, 'scale']);
        });
    }

    /**
     * Laravel's named limiter for the dashboard's routes, defined on every boot: cached
     * routes still name it, and route:cache keeps no limiter (the security review).
     */
    public static function limiter(Config $config): void
    {
        $perMinute = $config->get('dynamic-throttle.dashboard.rate_limit');
        $perMinute = is_int($perMinute) ? $perMinute : 120;

        // A user is counted by id, a guest by IP, each under its own prefix so the two never meet.
        RateLimiter::for(self::LIMITER, function (Request $request) use ($perMinute): Limit {
            $id = $request->user()?->getAuthIdentifier();

            return $perMinute > 0
                ? Limit::perMinute($perMinute)->by(is_scalar($id) ? "user:{$id}" : 'ip:'.$request->ip())
                : Limit::none();
        });
    }

    /**
     * What every route of the group runs after dashboard.middleware: the headers first, so
     * a 403 or a 429 carries them too, then the limit, then the gates.
     *
     * @param  'read'|'inspect'|'write'  $ability
     * @return list<string>
     */
    public static function middleware(string $ability): array
    {
        return [SecurityHeaders::class, 'throttle:'.self::LIMITER, Authorize::class.':'.$ability];
    }
}
