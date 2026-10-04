<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http\Middleware;

use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\Failure\Cooldown;
use Afzal\DynamicThrottle\Engine\Failure\ReportThrottle;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\RuleFailure;
use Afzal\DynamicThrottle\Events\RateLimitWouldBlock;
use Afzal\DynamicThrottle\Events\StorageUnavailable;
use Afzal\DynamicThrottle\Http\RateLimitHeaders;
use Afzal\DynamicThrottle\Http\RequestIdentityResolver;
use Afzal\DynamicThrottle\Http\RequestTarget;
use Afzal\DynamicThrottle\Http\Untouched;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\InvalidManifest;
use Afzal\DynamicThrottle\Manifest\ManifestRepository;
use Afzal\DynamicThrottle\Manifest\ManifestUnavailable;
use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Throwable;

/**
 * Applies the rules to a request (ADR-0004, ADR-0008, SPEC-010), and tells the
 * app after the response which dry-run rules would have blocked it (SPEC-011)
 * and whether the counter store failed (SPEC-012). Only the 429 and the 503 of
 * a fail-closed rule leave it: anything else the package throws is reported,
 * at most once per cause and cooldown, and the request goes through (ADR-0020
 * point 1). Strict mode rethrows programming mistakes instead.
 */
final readonly class DynamicThrottleMiddleware
{
    public const string ALIAS = 'dynamic.throttle';

    /** The package's own routes, such as the dashboard's, are never limited (ADR-0004 point 5). */
    public const string OWN_ROUTES = 'dynamic-throttle.';

    /**
     * The request attribute that carries the events from handle() to terminate():
     * Laravel builds a new middleware for terminate(), and the request is per
     * request, under Octane too (SPEC-011 decision 2).
     */
    private const string EVENTS = 'dynamic-throttle.events';

    public function __construct(
        // The rest is resolved in handle(), where its errors are caught: a config
        // mistake throws while the counter store is built (ADR-0020 point 1).
        private Container $container,
        private Config $config,
        private Clock $clock,
        private DynamicThrottle $dynamicThrottle,
    ) {}

    /**
     * @param  Closure(Request): mixed  $next
     *
     * @throws ThrottleRequestsException|HttpResponseException the 429
     * @throws ServiceUnavailableHttpException the 503 of a fail-closed rule that could not be checked
     * @throws Throwable in strict mode, a programming mistake
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
     * Dispatches the events handle() kept, once the response is sent (SPEC-011
     * decision 2, SPEC-012). A listener that throws is reported, and the next event
     * still goes out. Laravel also passes the response, which this does not need.
     */
    public function terminate(Request $request): void
    {
        try {
            $events = $request->attributes->get(self::EVENTS);
            // Taken, not read: a middleware the app also lists globally terminates twice.
            $request->attributes->remove(self::EVENTS);

            if (! is_array($events)) {
                return;
            }

            $dispatcher = $this->container->make(Dispatcher::class);

            foreach ($events as $event) {
                if (! $event instanceof RateLimitWouldBlock && ! $event instanceof StorageUnavailable) {
                    continue;
                }

                try {
                    $dispatcher->dispatch($event);
                } catch (Throwable $exception) {
                    $this->report($exception);
                }
            }
        } catch (Throwable $exception) {
            $this->report($exception);
        }
    }

    /**
     * The headers for the response, or the exception to throw: the 429, the 503, or in
     * strict mode a mistake. In order, the first step that lets the request through
     * untouched ends it: the kill switch, the package's own routes, except, bypass,
     * and no matched rule. dynamic-throttle:explain runs the same steps (SPEC-014).
     *
     * @return array<string, int>|Throwable
     */
    private function decide(Request $request): array|Throwable
    {
        try {
            if (Untouched::beforePath($this->config, $request) instanceof Untouched) {
                return [];
            }

            $target = RequestTarget::fromRequest($request);

            if (Untouched::except($this->config, $target->path) instanceof Untouched) {
                return [];
            }

            $manifest = $this->container->make(ManifestRepository::class)->current();
            // The runtime settings apply until their end time, by this request's clock (SPEC-013).
            $now = intdiv($this->clock->microseconds(), 1_000_000);

            if (Untouched::bypass($manifest->settings, $now) instanceof Untouched) {
                return [];
            }

            $rules = (new RuleMatcher)->match($manifest, $target->method, $target->path)->rules;

            if (Untouched::noRule($rules) instanceof Untouched) {
                return [];
            }

            $identities = $this->container->make(RequestIdentityResolver::class, ['request' => $request, 'exemptions' => $manifest->exemptions]);
            $evaluation = $this->container->make(RuleEvaluator::class)->evaluate($rules, $identities, $manifest->settings->scalePercent($now));
            $mistake = $this->strictMistake($evaluation);
            // Also when strict mode rethrows a mistake: an outage in the same request is still told.
            $this->keepEvents($request, $target, $evaluation);
            $this->reportFailures($evaluation, except: $mistake);

            if ($mistake instanceof Throwable) {
                return $mistake;
            }

            return match (true) {
                $evaluation->rejection instanceof RuleAttempt => $this->rejection($evaluation->rejection),
                $evaluation->unavailable instanceof CompiledRule => $this->unavailable(),
                default => RateLimitHeaders::counted($evaluation),
            };
        } catch (Throwable $exception) {
            if ($this->isMistake($exception) && $this->isStrict()) {
                return $exception;
            }

            $this->report($exception);

            return [];
        }
    }

    private function isStrict(): bool
    {
        return $this->dynamicThrottle->isStrict();
    }

    /**
     * A programming mistake, which strict mode rethrows (SPEC-012 decision 4). Never
     * what code cannot prevent: a storage error, or a client IP that cannot be read.
     * The manifest reports its own errors, and never throws them.
     */
    private function isMistake(Throwable $exception): bool
    {
        return match (true) {
            // The manifest, as L1 holds it, follows the policy too (SPEC-028 AC-14).
            $exception instanceof StorageUnavailableException, $exception instanceof InvalidManifest, $exception instanceof ManifestUnavailable => false,
            $exception instanceof IdentityUnavailableException => ! $exception->fromClient,
            default => true,
        };
    }

    /**
     * In strict mode, the first mistake the evaluation lists: a custom scope or a guard
     * that failed, for a rule or for an exemption check.
     */
    private function strictMistake(Evaluation $evaluation): ?Throwable
    {
        if ($evaluation->failures === [] && $evaluation->exemptionFailures === []) {
            return null;
        }

        if (! $this->isStrict()) {
            return null;
        }

        foreach ([...$evaluation->failures, ...$evaluation->exemptionFailures] as $failure) {
            if ($this->isMistake($failure->exception)) {
                return $failure->exception;
            }
        }

        return null;
    }

    /**
     * The events for terminate(): one RateLimitWouldBlock for each dry-run rule that
     * would have rejected the request, in evaluation order, whatever the outcome
     * (SPEC-011), then StorageUnavailable when the counter store failed (SPEC-012).
     * A failure here is reported, and never changes the decision, the 429 included.
     */
    private function keepEvents(Request $request, RequestTarget $target, Evaluation $evaluation): void
    {
        try {
            $events = array_map(
                fn (RuleAttempt $wouldBlock): RateLimitWouldBlock => new RateLimitWouldBlock(
                    ruleId: $wouldBlock->rule->id,
                    ruleName: $wouldBlock->rule->name,
                    limit: $wouldBlock->attempt->limit,
                    windowSeconds: $wouldBlock->attempt->windowSeconds,
                    scope: $wouldBlock->rule->scope,
                    identityHash: $wouldBlock->attempt->key->identityHash,
                    retryAfter: $wouldBlock->attempt->retryAfter,
                    method: $target->method,
                    path: $target->path,
                ),
                $evaluation->wouldBlock,
            );

            $storageFailure = $this->storageFailure($evaluation);

            if ($storageFailure instanceof StorageUnavailable) {
                $events[] = $storageFailure;
            }

            if ($events !== []) {
                $request->attributes->set(self::EVENTS, $events);
            }
        } catch (Throwable $exception) {
            $this->report($exception);
        }
    }

    /**
     * The request's first storage failure: a failed attempt, or else a failed release,
     * which keeps its 429 (ADR-0021). With the error's root cause, so a store that
     * could not be built names the config mistake.
     */
    private function storageFailure(Evaluation $evaluation): ?StorageUnavailable
    {
        foreach ($evaluation->failures as $failure) {
            if ($failure->exception instanceof StorageUnavailableException) {
                return $this->storageUnavailable($failure->exception, release: false);
            }
        }

        $release = $evaluation->releaseFailures[0] ?? null;

        return $release instanceof RuleFailure ? $this->storageUnavailable($release->exception, release: true) : null;
    }

    private function storageUnavailable(Throwable $exception, bool $release): StorageUnavailable
    {
        while (($previous = $exception->getPrevious()) instanceof Throwable) {
            $exception = $previous;
        }

        return new StorageUnavailable($exception::class, $exception->getMessage(), $release, $this->container->make(Cooldown::class)->seconds);
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
     * The 503 of a fail-closed rule, which the app's handler renders as abort(503):
     * the client did nothing wrong (ADR-0010 point 2). Retry-After is what is left of
     * the cooldown, or the whole cooldown when none runs, such as for an identity
     * that failed, and at least a second.
     */
    private function unavailable(): ServiceUnavailableHttpException
    {
        $cooldown = $this->container->make(Cooldown::class);
        $left = $cooldown->secondsLeft();

        return new ServiceUnavailableHttpException(max(1, $left > 0 ? $left : $cooldown->seconds), 'Service Unavailable.');
    }

    /**
     * Every failure the evaluation lists, once per cause: rules that use a scope that
     * failed share its exception, and those that count by an unreadable client IP each
     * wrap the same one (SPEC-008). A failed release keeps the 429 (ADR-0021). The
     * mistake strict mode rethrows is left to the app's handler, which reports it.
     */
    private function reportFailures(Evaluation $evaluation, ?Throwable $except = null): void
    {
        $reported = $except instanceof Throwable ? [spl_object_id($except->getPrevious() ?? $except) => true] : [];

        foreach ([...$evaluation->failures, ...$evaluation->releaseFailures, ...$evaluation->exemptionFailures] as $failure) {
            $id = spl_object_id($failure->exception->getPrevious() ?? $failure->exception);

            if (! isset($reported[$id])) {
                $reported[$id] = true;
                $this->report($failure->exception);
            }
        }
    }

    /**
     * Each cause at most once per cooldown (SPEC-012 decision 5). report() rethrows
     * when the app's handler cannot log, and even then only the 429 and the 503 may
     * leave the middleware.
     */
    private function report(Throwable $exception): void
    {
        try {
            if ($this->container->make(ReportThrottle::class)->allows($exception)) {
                report($exception);
            }
        } catch (Throwable) {
            // Nothing is left to tell, and the request must still go through.
        }
    }
}
