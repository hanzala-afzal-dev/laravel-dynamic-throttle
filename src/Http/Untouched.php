<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;

/**
 * The steps that let a request through untouched, which the middleware and
 * dynamic-throttle:explain share (SPEC-014), so explain cannot disagree with a
 * request. They run in this order, and the first that applies ends the request's
 * checks: the kill switch and the package's own routes, except, bypass, and no
 * matched rule. Each step gives null when it lets the request on.
 *
 * @internal
 */
final readonly class Untouched
{
    public function __construct(
        public UntouchedReason $reason,
        /** The except pattern that matched the path, for UntouchedReason::Except. */
        public ?string $pattern = null,
    ) {}

    /**
     * The steps before the rules are read: the kill switch, then the package's own
     * routes, which are never limited (ADR-0004 point 5): by their names, and by the
     * dashboard's path, which global middleware sees before routing (SPEC-018).
     */
    public static function beforePath(Config $config, Request $request): ?self
    {
        if ($config->get('dynamic-throttle.enabled') !== true) {
            return new self(UntouchedReason::Disabled);
        }

        $route = $request->route();

        if ($route instanceof Route && str_starts_with((string) $route->getName(), DynamicThrottleMiddleware::OWN_ROUTES)) {
            return new self(UntouchedReason::OwnRoute);
        }

        if (DashboardPath::covers($config, $request->decodedPath())) {
            return new self(UntouchedReason::OwnRoute);
        }

        return null;
    }

    /**
     * except: paths no rule ever applies to, matched as rule patterns are (SPEC-007).
     * Gives the first pattern that matches, as configured.
     */
    public static function except(Config $config, string $path): ?self
    {
        $patterns = $config->get('dynamic-throttle.except');

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            // A leading slash is ignored, and the root stays "/", as in RuleIndex.
            $trimmed = ltrim($pattern, '/');

            if (Str::is($trimmed === '' ? '/' : $trimmed, $path)) {
                return new self(UntouchedReason::Except, $pattern);
            }
        }

        return null;
    }

    /**
     * Bypass, until its end time by the request's clock (SPEC-013).
     *
     * @param  int  $now  Unix seconds
     */
    public static function bypass(ManifestSettings $settings, int $now): ?self
    {
        return $settings->bypasses($now) ? new self(UntouchedReason::Bypass) : null;
    }

    /**
     * @param  list<CompiledRule>  $rules  the rules that match the request
     */
    public static function noRule(array $rules): ?self
    {
        return $rules === [] ? new self(UntouchedReason::NoRule) : null;
    }
}
