<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http\Middleware;

use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Http\RateLimitHeaders;
use Afzal\DynamicThrottle\Http\RequestIdentityResolver;
use Afzal\DynamicThrottle\Http\RequestTarget;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Applies the rules to a request (ADR-0004, ADR-0008, SPEC-010). Only the 429
 * leaves it: anything else the package throws while deciding is reported, and
 * the request goes through (ADR-0020 point 1).
 */
final readonly class DynamicThrottleMiddleware
{
    public const string ALIAS = 'dynamic.throttle';

    /** The package's own routes, such as the dashboard's, are never limited (ADR-0004 point 5). */
    public const string OWN_ROUTES = 'dynamic-throttle.';

    public function __construct(
        // The rest is resolved in handle(), where its errors are caught: a config
        // mistake throws while the counter store is built (ADR-0020 point 1).
        private Container $container,
        private Config $config,
        private Clock $clock,
    ) {}

    /**
     * @param  Closure(Request): mixed  $next
     *
     * @throws ThrottleRequestsException|HttpResponseException the 429, and nothing else
     */
    public function handle(Request $request, Closure $next): mixed
    {
        $decision = $this->decide($request);

        if ($decision instanceof Throwable) {
            throw $decision;
        }

        // Outside the try: the app's own exceptions are never touched.
        $response = $next($request);

        if ($decision !== [] && $response instanceof Response) {
            $response->headers->add($decision);
        }

        return $response;
    }

    /**
     * The headers for the response, or the 429 to throw. In order, the first step that
     * lets the request through untouched ends it: the kill switch, the package's own
     * routes, except, and no matched rule.
     *
     * @return array<string, int>|ThrottleRequestsException|HttpResponseException
     */
    private function decide(Request $request): array|ThrottleRequestsException|HttpResponseException
    {
        try {
            if ($this->config->get('dynamic-throttle.enabled') !== true || $this->isOwnRoute($request)) {
                return [];
            }

            $target = RequestTarget::fromRequest($request);

            if ($this->isExcepted($target->path)) {
                return [];
            }

            $manifest = $this->container->make(ManifestRepository::class)->current();
            $rules = (new RuleMatcher)->match($manifest, $target->method, $target->path)->rules;

            if ($rules === []) {
                return [];
            }

            $identities = $this->container->make(RequestIdentityResolver::class, ['request' => $request, 'exemptions' => $manifest->exemptions]);
            $evaluation = $this->container->make(RuleEvaluator::class)->evaluate($rules, $identities);
            $this->reportFailures($evaluation);

            return $evaluation->rejection instanceof RuleAttempt
                ? $this->rejection($evaluation->rejection)
                : RateLimitHeaders::counted($evaluation);
        } catch (Throwable $exception) {
            $this->report($exception);

            return [];
        }
    }

    private function isOwnRoute(Request $request): bool
    {
        $route = $request->route();

        return $route instanceof Route && str_starts_with((string) $route->getName(), self::OWN_ROUTES);
    }

    /**
     * except: paths no rule ever applies to, matched as rule patterns are (SPEC-007).
     */
    private function isExcepted(string $path): bool
    {
        $patterns = $this->config->get('dynamic-throttle.except');

        foreach (is_array($patterns) ? $patterns : [] as $pattern) {
            if (! is_string($pattern)) {
                continue;
            }

            // A leading slash is ignored, and the root stays "/", as in RuleIndex.
            $pattern = ltrim($pattern, '/');

            if (Str::is($pattern === '' ? '/' : $pattern, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Laravel's own 429, or, like Limit::response(), the rule's own body with the same headers.
     */
    private function rejection(RuleAttempt $rejection): ThrottleRequestsException|HttpResponseException
    {
        $headers = RateLimitHeaders::rejected($rejection, intdiv($this->clock->microseconds(), 1_000_000));
        $override = $rejection->rule->responseOverride;

        return $override === null
            ? new ThrottleRequestsException('Too Many Attempts.', null, $headers)
            : new HttpResponseException(new JsonResponse($override, 429, $headers));
    }

    /**
     * Every failure the evaluation lists, once per cause: rules that use a scope that
     * failed share its exception, and those that count by an unreadable client IP each
     * wrap the same one (SPEC-008). A failed release keeps the 429 (ADR-0021).
     */
    private function reportFailures(Evaluation $evaluation): void
    {
        $reported = [];

        foreach ([...$evaluation->failures, ...$evaluation->releaseFailures, ...$evaluation->exemptionFailures] as $failure) {
            $id = spl_object_id($failure->exception->getPrevious() ?? $failure->exception);

            if (! isset($reported[$id])) {
                $reported[$id] = true;
                $this->report($failure->exception);
            }
        }
    }

    /**
     * report() rethrows when the app's handler cannot log, and even then only the
     * 429 may leave the middleware.
     */
    private function report(Throwable $exception): void
    {
        try {
            report($exception);
        } catch (Throwable) {
            // Nothing is left to tell, and the request must still go through.
        }
    }
}
