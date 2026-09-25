<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Contracts\Clock;
use Afzal\DynamicThrottle\Engine\Contracts\ExemptionSubjects;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
use Afzal\DynamicThrottle\Engine\Exemptions\ExemptionSet;
use Afzal\DynamicThrottle\Engine\Identity\ClientIp;
use Afzal\DynamicThrottle\Engine\Identity\IdentityHasher;
use Afzal\DynamicThrottle\Engine\Identity\ScopeIdentity;
use Afzal\DynamicThrottle\Enums\Scope;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Stringable;
use Throwable;

/**
 * The identities one request counts under (ADR-0009, SPEC-008), and the
 * exemptions that free it from rules (SPEC-009). Build one per request:
 * make(RequestIdentityResolver::class, ['request' => $request, 'exemptions' => $manifest->exemptions]).
 * Each scope is resolved at most once, however many rules and exemptions use it.
 */
final class RequestIdentityResolver implements ExemptionSubjects, IdentityResolver
{
    /** @var array<string, string|IdentityUnavailableException|null> each scope's value for this request, or its error, by scope name */
    private array $values = [];

    /** @var array<string, string> the hashes of the scope values exemptions were checked against, by scope name */
    private array $hashes = [];

    /** Whether the client IP was read: it is read at most once, and only when needed. */
    private bool $ipRead = false;

    /** The client IP as the app's trusted proxies give it, or null when the request has none. */
    private ?string $ipAddress = null;

    /** Why the client IP could not be read. */
    private ?Throwable $ipFailure = null;

    /** The client IP as rules count it, grouped (SPEC-008). */
    private ?string $groupedIp = null;

    /** The one error every exemption check that needs the unreadable client IP fails with. */
    private ?IdentityUnavailableException $exemptionIpError = null;

    /** The time exemptions expire against, read once per request: Unix microseconds. */
    private ?int $now = null;

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
        private readonly DynamicThrottle $scopes,
        private readonly Container $container,
        private readonly IdentityHasher $hasher,
        private readonly IdentitySettings $settings,
        private readonly Clock $clock,
        private readonly ExemptionSet $exemptions = new ExemptionSet,
    ) {}

    public function exemption(CompiledRule $rule): ?string
    {
        // Most apps have no exemptions: they pay nothing, not even a clock read.
        if ($this->exemptions->all() === []) {
            return null;
        }

        return $this->exemptions->match($rule->id, $this->now ??= $this->clock->microseconds(), $this);
    }

    public function resolve(CompiledRule $rule): ?string
    {
        $value = match ($rule->scope) {
            Scope::Ip, Scope::Global => null,
            default => $this->value($rule->scope, $rule->scopeParameter),
        };
        $identity = ScopeIdentity::for($rule, $value, fn (): string => $this->groupedIp($rule));

        return $identity === null ? null : $this->hasher->hash($identity);
    }

    /**
     * For exemptions: the whole address, before IPv6 grouping (SPEC-009).
     *
     * @throws IdentityUnavailableException
     */
    public function ip(): ?string
    {
        try {
            return $this->clientIp();
        } catch (Throwable $exception) {
            // One exception for every rule's check, as a failing scope gives, so it is reported once.
            throw $this->exemptionIpError ??= IdentityUnavailableException::because('The exemptions could not be checked: the client IP could not be read: '.$exception->getMessage(), $exception);
        }
    }

    /**
     * For exemptions: the same user the user scope counts, with the same guards.
     *
     * @throws IdentityUnavailableException
     */
    public function user(): ?string
    {
        return $this->hashed(Scope::User, null);
    }

    /**
     * For exemptions: the value the custom scope's resolver returns, asked once for
     * exemptions and rules together.
     *
     * @throws IdentityUnavailableException
     */
    public function custom(string $scope): ?string
    {
        return $this->hashed(Scope::Custom, $scope);
    }

    /**
     * A scope's value, hashed as the compiler hashes exemptions (ADR-0024), or null
     * when the request has none.
     *
     * @throws IdentityUnavailableException
     */
    private function hashed(Scope $scope, ?string $parameter): ?string
    {
        $value = $this->value($scope, $parameter);

        return $value === null ? null : $this->hashes[$this->scopeName($scope, $parameter)] ??= $this->hasher->hash($value);
    }

    /**
     * Keyed by the scope's name, so a parameter left on a scope that takes none
     * never asks it twice. A scope that fails is not asked again either: every
     * rule and exemption that uses it fails with the same exception, so a failing
     * resolver or guard also runs once.
     *
     * @throws IdentityUnavailableException
     */
    private function value(Scope $scope, ?string $parameter): ?string
    {
        $key = $this->scopeName($scope, $parameter);

        if (! array_key_exists($key, $this->values)) {
            try {
                $this->values[$key] = match ($scope) {
                    Scope::User => $this->userValue(),
                    // Passport and jwt-auth accept "Bearer  abc" as the token abc, so one token must be one client.
                    Scope::ApiToken => trim((string) $this->request->bearerToken(), " \t"),
                    Scope::Header => $this->request->headers->get((string) $parameter),
                    Scope::Custom => $this->customValue((string) $parameter),
                    Scope::Ip, Scope::Global => null,
                };
            } catch (IdentityUnavailableException $exception) {
                $this->values[$key] = $exception;
            }
        }

        $value = $this->values[$key];

        return $value instanceof IdentityUnavailableException ? throw $value : $value;
    }

    /**
     * Read at most once, and only when a rule or an exemption needs it. Symfony
     * throws when trusted proxies send conflicting Forwarded and X-Forwarded-For
     * headers, but only the first time: later reads answer 0.0.0.0. So the error is
     * kept, and everything that needs the IP fails with it. The read goes through a
     * copy of the request, so the app's own $request->ip() still throws, as it would
     * without the package.
     *
     * @throws Throwable why the client IP could not be read
     */
    private function clientIp(): ?string
    {
        if (! $this->ipRead) {
            $this->ipRead = true;

            try {
                $this->ipAddress = (clone $this->request)->ip();
            } catch (Throwable $exception) {
                $this->ipFailure = $exception;
            }
        }

        return $this->ipFailure instanceof Throwable ? throw $this->ipFailure : $this->ipAddress;
    }

    /**
     * The client IP as a rule counts it, grouped (SPEC-008).
     *
     * @throws IdentityUnavailableException
     */
    private function groupedIp(CompiledRule $rule): string
    {
        try {
            $ip = $this->clientIp();
        } catch (Throwable $exception) {
            throw IdentityUnavailableException::because("The identity for {$this->scopeName($rule->scope, $rule->scopeParameter)} could not be resolved: the client IP could not be read: {$exception->getMessage()}", $exception);
        }

        return $this->groupedIp ??= ClientIp::group($ip, $this->settings->ipv6Prefix);
    }

    /**
     * How errors and the memo name a scope: "user", or "custom:plan". Only header and
     * custom scopes take a parameter.
     */
    private function scopeName(Scope $scope, ?string $parameter): string
    {
        return in_array($scope, [Scope::Header, Scope::Custom], true)
            ? "{$scope->value}:{$parameter}"
            : $scope->value;
    }

    /**
     * The user and its class, so two user models with the same id never share a counter.
     *
     * @throws IdentityUnavailableException
     */
    private function userValue(): ?string
    {
        try {
            $user = $this->settings->guards === null ? $this->authenticatedUser() : $this->firstUser($this->settings->guards);

            if (! $user instanceof Authenticatable) {
                return null;
            }

            $id = $user->getAuthIdentifier();
            $id = $id instanceof Stringable ? (string) $id : $id;
        } catch (Throwable $exception) {
            throw IdentityUnavailableException::because('The identity for user could not be resolved: '.$exception->getMessage(), $exception);
        }

        return match (true) {
            $id === null, $id === '' => null,
            is_string($id), is_int($id) => $user::class.':'.$id,
            default => throw IdentityUnavailableException::because('The identity for user could not be resolved: '.$user::class.'::getAuthIdentifier() returned '.get_debug_type($id).'.'),
        };
    }

    /**
     * The user the request authenticated as, only when its guard already holds it:
     * this scope never triggers a lookup of its own (ADR-0020 point 5).
     */
    private function authenticatedUser(): ?Authenticatable
    {
        $guard = $this->auth->guard();

        return $guard->hasUser() ? $guard->user() : null;
    }

    /**
     * Asks each guard in order, paying each guard's own lookup (scopes.user.guards).
     *
     * @param  list<string>  $guards
     */
    private function firstUser(array $guards): ?Authenticatable
    {
        foreach ($guards as $guard) {
            $user = $this->auth->guard($guard)->user();

            if ($user !== null) {
                return $user;
            }
        }

        return null;
    }

    /**
     * @throws IdentityUnavailableException
     */
    private function customValue(string $name): ?string
    {
        $resolver = $this->scopes->scopeResolver($name);

        if ($resolver === null) {
            throw IdentityUnavailableException::because("The identity for custom:{$name} could not be resolved: no resolver is registered for it. Register one with DynamicThrottle::scope('{$name}', ...).");
        }

        try {
            $value = match (true) {
                $resolver instanceof Closure => $resolver($this->request),
                $resolver instanceof ScopeResolver => $resolver->resolve($this->request),
                default => $this->containerResolver($resolver)->resolve($this->request),
            };
            $value = $value instanceof Stringable ? (string) $value : $value;
        } catch (Throwable $exception) {
            throw IdentityUnavailableException::because("The identity for custom:{$name} could not be resolved: ".$exception->getMessage(), $exception);
        }

        return match (true) {
            $value === null, $value === '' => null,
            is_string($value) => $value,
            is_int($value) => (string) $value,
            default => throw IdentityUnavailableException::because("The identity for custom:{$name} could not be resolved: its resolver returned ".get_debug_type($value).', not a string, an integer or null.'),
        };
    }

    /**
     * @param  class-string<ScopeResolver>  $class
     */
    private function containerResolver(string $class): ScopeResolver
    {
        $resolver = $this->container->make($class);

        return $resolver instanceof ScopeResolver
            ? $resolver
            : throw IdentityUnavailableException::because("The container built {$class} as ".get_debug_type($resolver).', not a ScopeResolver.');
    }
}
