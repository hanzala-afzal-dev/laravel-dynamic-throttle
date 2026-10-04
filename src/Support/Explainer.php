<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Engine\Attempt;
use Afzal\DynamicThrottle\Engine\AttemptBatch;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\CounterReader;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Engine\CounterReading;
use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Engine\KeyBuilder;
use Afzal\DynamicThrottle\Engine\Matching\RuleMatcher;
use Afzal\DynamicThrottle\Engine\PlannedAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\Evaluation;
use Afzal\DynamicThrottle\Engine\Stacking\EvaluationOutcome;
use Afzal\DynamicThrottle\Engine\Stacking\Plan;
use Afzal\DynamicThrottle\Engine\Stacking\RuleAttempt;
use Afzal\DynamicThrottle\Engine\Stacking\RuleEvaluator;
use Afzal\DynamicThrottle\Engine\Stacking\Scale;
use Afzal\DynamicThrottle\Http\IdentitySettings;
use Afzal\DynamicThrottle\Http\Middleware\DynamicThrottleMiddleware;
use Afzal\DynamicThrottle\Http\RateLimitHeaders;
use Afzal\DynamicThrottle\Http\RequestIdentityResolver;
use Afzal\DynamicThrottle\Http\RequestTarget;
use Afzal\DynamicThrottle\Http\Untouched;
use Afzal\DynamicThrottle\Http\UntouchedReason;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\Manifest;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;
use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use Afzal\DynamicThrottle\Support\Explanation\DecisionOutcome;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedDecision;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedRoute;
use Afzal\DynamicThrottle\Support\Explanation\ExplainedRule;
use Afzal\DynamicThrottle\Support\Explanation\Explanation;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationFailed;
use Afzal\DynamicThrottle\Support\Explanation\ExplanationRequest;
use Afzal\DynamicThrottle\Support\Explanation\RuleStatus;
use Closure;
use Illuminate\Auth\AuthManager;
use Illuminate\Auth\Middleware\AuthenticateWithBasicAuth;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Contracts\Container\Container;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Events\NullDispatcher;
use Illuminate\Foundation\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Routing\Router;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * Runs the middleware's decision on a request it describes, without counting it
 * (SPEC-014): the steps that let a request through untouched, the matching, the
 * identities, the evaluator's plan and its decision, through the classes the
 * middleware uses, so an explanation cannot disagree with a request. The
 * counters are read, never written, and the manifest is read as status reads
 * it, never rebuilt.
 *
 * @internal
 */
final readonly class Explainer
{
    public function __construct(
        private Container $container,
        private Config $config,
        /** The store the manifest lives in, which requests read it from (ADR-0005 point 6). */
        private CacheRepository $cache,
        private KeyBuilder $keys,
        private Clock $clock,
        private LimiterStore $store,
        private RuleEvaluator $evaluator,
        /** matching.max_rules_per_request, which the evaluator applies (ADR-0025). */
        private int $maxRulesPerRequest,
        /** A fail-closed rule's 503 asks to retry after the cooldown, as from a server out of it. */
        private int $cooldownSeconds,
    ) {}

    /**
     * @throws ExplanationFailed when the request needs the manifest and it is missing or cannot be read, or the user or the guard cannot be used
     */
    public function explain(ExplanationRequest $input): Explanation
    {
        $request = $input->toRequest();
        $target = RequestTarget::fromRequest($request);
        $now = intdiv($this->clock->microseconds(), 1_000_000);
        [$route, $global] = $this->route($request);
        $manifest = $this->manifest();

        return $this->as($input, $request, $route, function (?string $guard) use ($input, $request, $target, $route, $global, $manifest, $now): Explanation {
            [$rules, $read, $error, $decision] = $this->evaluate($request, $target, $route, $global, $manifest, $now);
            $manifestRead = $manifest instanceof Manifest;

            return new Explanation(
                $input, $target->path, $guard, $manifestRead ? $manifest : null, $manifestRead ? null : $manifest->getMessage(),
                ($manifestRead ? $manifest->settings : ManifestSettings::none())->at($now), $route, $global, $rules, $read, $error, $decision, $this->maxRulesPerRequest,
            );
        });
    }

    /**
     * The middleware's steps: those that let the request through untouched, the
     * matching, the plan, and the decision from the counters. As in the middleware,
     * the manifest is needed only once the steps before it let the request on.
     *
     * @param  Manifest|ExplanationFailed  $manifest  the manifest, or why it cannot be read
     * @param  int  $now  Unix seconds
     * @return array{list<ExplainedRule>, bool, ?string, ExplainedDecision} the rules, whether the counters were read, why not, and the decision
     *
     * @throws ExplanationFailed when the request needs the manifest and it cannot be read
     */
    private function evaluate(Request $request, RequestTarget $target, ?ExplainedRoute $route, bool $global, Manifest|ExplanationFailed $manifest, int $now): array
    {
        $untouched = $this->beforeManifest($request, $target, $route, $global);

        if ($untouched instanceof Untouched) {
            return [[], false, null, new ExplainedDecision(DecisionOutcome::Untouched, $untouched)];
        }

        if ($manifest instanceof ExplanationFailed) {
            throw $manifest;
        }

        $untouched = Untouched::bypass($manifest->settings, $now);
        $rules = $untouched instanceof Untouched ? [] : (new RuleMatcher)->match($manifest, $target->method, $target->path)->rules;
        $untouched ??= Untouched::noRule($rules);

        if ($untouched instanceof Untouched) {
            return [[], false, null, new ExplainedDecision(DecisionOutcome::Untouched, $untouched)];
        }

        $identities = $this->container->make(RequestIdentityResolver::class, ['request' => $request, 'exemptions' => $manifest->exemptions]);
        $percent = $manifest->settings->scalePercent($now);
        $plan = $this->evaluator->plan($rules, $identities, $percent);
        [$readings, $batch, $error] = $this->read($plan);
        $evaluation = $this->store instanceof CounterReader || ! $this->enforces($plan->attempts) ? $this->evaluator->decide($plan, $batch) : null;

        return [$this->rules($rules, $plan, $readings, $identities, $percent), $error === null, $error, $this->decision($evaluation, $now)];
    }

    /**
     * The manifest servers read, from the cache store, without L0 or L1, and without
     * rebuilding a missing one: explain changes nothing (AC-9, AC-12). Or why it
     * cannot be read, which matters only once a request needs it.
     */
    private function manifest(): Manifest|ExplanationFailed
    {
        try {
            $json = $this->cache->get($this->keys->manifest());
            $manifest = is_string($json) ? ManifestCodec::decode($json) : null;
        } catch (Throwable $exception) {
            return new ExplanationFailed('The manifest cannot be read: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        return $manifest ?? new ExplanationFailed('The manifest is missing: run php artisan dynamic-throttle:compile.');
    }

    /**
     * Runs the explanation with the request bound, as Laravel's kernel binds it. With
     * --user, the user is looked up, and authenticated on the guard, which becomes the
     * default, as the auth middleware leaves it, but only where the middleware would
     * see the user: after authentication, or through scopes.user.guards (decision 3).
     * Everything is put back afterwards, and no auth event fires (AC-9).
     *
     * @template T
     *
     * @param  Closure(?string): T  $explain  given the guard the user was looked up with
     * @return T
     *
     * @throws ExplanationFailed
     */
    private function as(ExplanationRequest $input, Request $request, ?ExplainedRoute $route, Closure $explain): mixed
    {
        $previousRequest = $this->container->bound('request') ? $this->container->make('request') : null;
        $this->container->instance('request', $request);

        try {
            if ($input->user === null) {
                return $explain(null);
            }

            $auth = $this->container->make('auth');
            $name = $input->guard ?? $auth->getDefaultDriver();
            $guard = $this->guard($auth, $name);
            $user = $this->provider($auth, $guard, $name)->retrieveById($input->user);

            if (! $user instanceof Authenticatable) {
                throw new ExplanationFailed("There is no user {$input->user} for the guard {$name}.");
            }

            if ($route?->afterAuth !== true && $this->container->make(IdentitySettings::class)->guards === null) {
                // Nothing looks the user up before dynamic.throttle runs: it counts a guest.
                return $explain($name);
            }

            return $this->authenticated($auth, $guard, $name, $user, $explain);
        } finally {
            if ($previousRequest !== null) {
                $this->container->instance('request', $previousRequest);
            }
        }
    }

    /**
     * Runs the explanation with the user on the guard, without the events setUser()
     * dispatches, so the app's listeners never run, then puts back the guard's user,
     * the default guard and the user resolver.
     *
     * @template T
     *
     * @param  Closure(?string): T  $explain
     * @return T
     */
    private function authenticated(AuthManager $auth, Guard $guard, string $name, Authenticatable $user, Closure $explain): mixed
    {
        $previousGuard = $auth->getDefaultDriver();
        $previousResolver = $auth->userResolver();
        $previousUser = $guard->hasUser() ? $guard->user() : null;
        $events = method_exists($guard, 'getDispatcher') && method_exists($guard, 'setDispatcher') ? $guard->getDispatcher() : null;

        try {
            if ($events instanceof Dispatcher) {
                $guard->setDispatcher(new NullDispatcher($events));
            }

            $guard->setUser($user);
            $auth->shouldUse($name);

            return $explain($name);
        } finally {
            $previousUser instanceof Authenticatable ? $guard->setUser($previousUser) : $this->forgetUser($guard);
            $auth->setDefaultDriver($previousGuard);
            $auth->resolveUsersUsing($previousResolver);

            if ($events instanceof Dispatcher) {
                $guard->setDispatcher($events);
            }
        }
    }

    /**
     * @throws ExplanationFailed
     */
    private function guard(AuthManager $auth, string $name): Guard
    {
        try {
            return $auth->guard($name);
        } catch (Throwable $exception) {
            throw new ExplanationFailed("The guard \"{$name}\" cannot be used: {$exception->getMessage()}", $exception->getCode(), previous: $exception);
        }
    }

    /**
     * The guard's own user provider, else the one its config names.
     *
     * @throws ExplanationFailed
     */
    private function provider(AuthManager $auth, Guard $guard, string $name): UserProvider
    {
        $provider = method_exists($guard, 'getProvider') ? $guard->getProvider() : null;

        if (! $provider instanceof UserProvider) {
            $configured = $this->config->get("auth.guards.{$name}.provider");
            $provider = $auth->createUserProvider(is_string($configured) ? $configured : null);
        }

        return $provider instanceof UserProvider ? $provider : throw new ExplanationFailed("The guard \"{$name}\" cannot be used: it has no user provider to look the user up.");
    }

    private function forgetUser(Guard $guard): void
    {
        if (method_exists($guard, 'forgetUser')) {
            $guard->forgetUser();
        }
    }

    /**
     * The app's route for the request, and whether dynamic.throttle is global
     * middleware. The HTTP kernel is resolved first, so the router holds the app's
     * groups, aliases and priority list, as for a web request. The route is bound to
     * the request only when it runs dynamic.throttle itself: global middleware runs
     * before routing, and finds none.
     *
     * @return array{?ExplainedRoute, bool}
     */
    private function route(Request $request): array
    {
        $global = $this->isGlobal($this->container->make(HttpKernel::class));
        $router = $this->container->make(Router::class);

        try {
            $route = $router->getRoutes()->match($request);
        } catch (HttpException) {
            // No route, or none for the method: 404 or 405.
            return [null, $global];
        }

        $middleware = array_map(
            fn (mixed $middleware): string => is_string($middleware) ? $middleware : get_debug_type($middleware),
            array_values($router->gatherRouteMiddleware($route)),
        );
        $position = $this->position($middleware);

        if ($position !== null) {
            $request->setRouteResolver(fn (): Route => $route);
        }

        $afterAuth = match (true) {
            $position !== null => $this->authenticates(array_slice($middleware, 0, $position), $router),
            // Global middleware runs before the route's, its authentication included.
            $global => false,
            default => null,
        };

        return [new ExplainedRoute($route->uri(), $route->getName(), $middleware, $position !== null || $global, $afterAuth), $global];
    }

    /**
     * Whether the app runs dynamic.throttle as global middleware. Another kernel than
     * Laravel's has no list to ask.
     */
    private function isGlobal(HttpKernel $kernel): bool
    {
        return $kernel instanceof Kernel && $kernel->hasMiddleware(DynamicThrottleMiddleware::class);
    }

    /**
     * Where dynamic.throttle runs among the route's middleware.
     *
     * @param  list<string>  $middleware
     */
    private function position(array $middleware): ?int
    {
        foreach ($middleware as $position => $name) {
            if (explode(':', $name, 2)[0] === DynamicThrottleMiddleware::class) {
                return $position;
            }
        }

        return null;
    }

    /**
     * Whether one of the middleware logs users in: Laravel's authentication, its basic
     * authentication, or one listed in middleware.after (ADR-0004 point 2).
     *
     * @param  list<string>  $middleware  those that run before dynamic.throttle
     */
    private function authenticates(array $middleware, Router $router): bool
    {
        $aliases = $router->getMiddleware();
        $after = [];

        foreach ((array) $this->config->get('dynamic-throttle.middleware.after') as $name) {
            if (is_string($name) && $name !== '') {
                $name = explode(':', $name, 2)[0];
                $after[] = is_string($aliases[$name] ?? null) ? $aliases[$name] : $name;
            }
        }

        foreach ($middleware as $name) {
            $class = explode(':', $name, 2)[0];

            if (is_a($class, AuthenticatesRequests::class, true) || is_a($class, AuthenticateWithBasicAuth::class, true) || in_array($class, $after, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The first step before the manifest that lets the request through untouched, in
     * the middleware's order, after one of explain's own: a request no route runs
     * dynamic.throttle for.
     */
    private function beforeManifest(Request $request, RequestTarget $target, ?ExplainedRoute $route, bool $global): ?Untouched
    {
        if (! $global && $route?->throttled !== true) {
            return new Untouched(UntouchedReason::NotAttached);
        }

        return Untouched::beforePath($this->config, $request)
            ?? Untouched::except($this->config, $target->path);
    }

    /**
     * Each planned attempt's counter, and the attempts they say the next request makes,
     * as the store's one call would answer them (decision 8). A failed read is a failed
     * store call, so the failure policy decides (SPEC-012).
     *
     * @return array{list<CounterReading>, AttemptBatch, ?string} the readings, by attempt; the batch; why the counters were not read
     */
    private function read(Plan $plan): array
    {
        if (! $this->store instanceof CounterReader) {
            return [[], new AttemptBatch, $this->store::class.' is a custom counter store: explain reads the counters of the package\'s cache and Redis drivers only.'];
        }

        $readings = [];

        try {
            foreach ($plan->attempts as $attempt) {
                $readings[] = $this->store->peek($attempt->key, $attempt->maxAttempts, $attempt->windowSeconds);
            }
        } catch (StorageUnavailableException $exception) {
            return [[], new AttemptBatch(failure: $exception), $this->message($exception)];
        }

        $next = array_map(fn (CounterReading $reading): Attempt => $reading->next, $readings);
        $rejected = false;

        foreach ($next as $index => $attempt) {
            $rejected = $rejected || (! $attempt->allowed && $plan->attempts[$index]->enforces);
        }

        // A rejection, or a plan that stops at a fail-closed rule, releases every count the call made.
        $released = array_fill(0, count($next), $rejected || $plan->stop instanceof CompiledRule);

        return [$readings, new AttemptBatch($next, $released), null];
    }

    /**
     * @param  list<PlannedAttempt>  $attempts
     */
    private function enforces(array $attempts): bool
    {
        foreach ($attempts as $attempt) {
            if ($attempt->enforces) {
                return true;
            }
        }

        return false;
    }

    /**
     * The error, with its root cause's message when it does not already say it.
     */
    private function message(Throwable $exception): string
    {
        $root = $exception;

        while (($previous = $root->getPrevious()) instanceof Throwable) {
            $root = $previous;
        }

        $message = $exception->getMessage();

        return $root === $exception || str_contains($message, $root->getMessage()) ? $message : $message.' '.$root->getMessage();
    }

    /**
     * Each matched rule, in evaluation order, with what it does with the request.
     *
     * @param  list<CompiledRule>  $rules
     * @param  list<CounterReading>  $readings  by the plan's attempts
     * @return list<ExplainedRule>
     */
    private function rules(array $rules, Plan $plan, array $readings, RequestIdentityResolver $identities, int $percent): array
    {
        $counted = [];

        foreach ($plan->rules as $index => $rule) {
            $counted[$rule->id] = [$plan->attempts[$index], $readings[$index] ?? null];
        }

        $exempted = [];

        foreach ($plan->exempted as $exemption) {
            $exempted[$exemption->rule->id] = $exemption->exemptionId;
        }

        $errors = [];

        foreach ([...$plan->exemptionFailures, ...$plan->failures] as $failure) {
            $errors[$failure->rule->id] = $failure->exception->getMessage();
        }

        $skipped = array_map(fn (CompiledRule $rule): string => $rule->id, $plan->skipped);
        $ignored = array_map(fn (CompiledRule $rule): string => $rule->id, $plan->ignored);
        $explained = [];

        foreach ($rules as $rule) {
            $limit = Scale::limit($rule->maxAttempts, $percent);
            $explained[] = match (true) {
                isset($counted[$rule->id]) => new ExplainedRule($rule, $limit, RuleStatus::Counts, $this->identity($identities, $rule), $counted[$rule->id][0]->key->identityHash, error: $errors[$rule->id] ?? null, counter: $counted[$rule->id][1]),
                isset($exempted[$rule->id]) => new ExplainedRule($rule, $limit, RuleStatus::Exempt, exemption: $exempted[$rule->id], error: $errors[$rule->id] ?? null),
                in_array($rule->id, $skipped, true) => new ExplainedRule($rule, $limit, RuleStatus::Skipped, error: $errors[$rule->id] ?? null),
                in_array($rule->id, $ignored, true) => new ExplainedRule($rule, $limit, RuleStatus::Ignored),
                isset($errors[$rule->id]) => new ExplainedRule($rule, $limit, RuleStatus::Failed, error: $errors[$rule->id]),
                default => new ExplainedRule($rule, $limit, RuleStatus::NotReached, stoppedBy: $plan->stop?->id),
            };
        }

        return $explained;
    }

    /**
     * The identity as given. The plan resolved it already, and the resolver keeps each
     * scope's value, so no resolver runs twice.
     */
    private function identity(RequestIdentityResolver $identities, CompiledRule $rule): ?string
    {
        try {
            return $identities->identity($rule);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The evaluator's decision, as the middleware turns it into a response, or unknown
     * without the counters an enforcing rule needs.
     *
     * @param  int  $now  Unix seconds
     */
    private function decision(?Evaluation $evaluation, int $now): ExplainedDecision
    {
        return match (true) {
            ! $evaluation instanceof Evaluation => new ExplainedDecision(DecisionOutcome::Unknown),
            $evaluation->rejection instanceof RuleAttempt => new ExplainedDecision(DecisionOutcome::Rejected, rule: $evaluation->rejection->rule->id, headers: RateLimitHeaders::rejected($evaluation->rejection, $now)),
            $evaluation->unavailable instanceof CompiledRule => new ExplainedDecision(DecisionOutcome::Unavailable, rule: $evaluation->unavailable->id, headers: ['Retry-After' => max(1, $this->cooldownSeconds)]),
            $evaluation->outcome === EvaluationOutcome::Failed => new ExplainedDecision(DecisionOutcome::Failed, rule: $evaluation->failures[0]->rule->id ?? null, headers: RateLimitHeaders::counted($evaluation)),
            default => new ExplainedDecision(DecisionOutcome::Allowed, headers: RateLimitHeaders::counted($evaluation)),
        };
    }
}
