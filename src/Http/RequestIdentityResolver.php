<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Afzal\DynamicThrottle\DynamicThrottle;
use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Exceptions\IdentityUnavailableException;
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
 * The identities one request counts under (ADR-0009, SPEC-008). Build one per
 * request: make(RequestIdentityResolver::class, ['request' => $request]). Each
 * scope is resolved at most once, however many rules use it.
 */
final class RequestIdentityResolver implements IdentityResolver
{
    /** @var array<string, ?string> each scope's value for this request, by scope and parameter */
    private array $values = [];

    private ?string $ip = null;

    public function __construct(
        private readonly Request $request,
        private readonly AuthFactory $auth,
        private readonly DynamicThrottle $scopes,
        private readonly Container $container,
        private readonly IdentityHasher $hasher,
        private readonly IdentitySettings $settings,
    ) {}

    public function resolve(CompiledRule $rule): ?string
    {
        $value = match ($rule->scope) {
            Scope::Ip, Scope::Global => null,
            default => $this->value($rule->scope, (string) $rule->scopeParameter),
        };
        $identity = ScopeIdentity::for($rule, $value, fn (): string => $this->ip($rule));

        return $identity === null ? null : $this->hasher->hash($identity);
    }

    /**
     * @throws IdentityUnavailableException
     */
    private function value(Scope $scope, string $parameter): ?string
    {
        $key = "{$scope->value}:{$parameter}";

        if (! array_key_exists($key, $this->values)) {
            $this->values[$key] = match ($scope) {
                Scope::User => $this->user(),
                // Passport and jwt-auth accept "Bearer  abc" as the token abc, so one token must be one client.
                Scope::ApiToken => trim((string) $this->request->bearerToken(), " \t"),
                Scope::Header => $this->request->headers->get($parameter),
                Scope::Custom => $this->custom($parameter),
                Scope::Ip, Scope::Global => null,
            };
        }

        return $this->values[$key];
    }

    /**
     * Read only when a rule needs it: Symfony throws when trusted proxies send
     * conflicting Forwarded and X-Forwarded-For headers.
     *
     * @throws IdentityUnavailableException
     */
    private function ip(CompiledRule $rule): string
    {
        try {
            return $this->ip ??= ClientIp::group($this->request->ip(), $this->settings->ipv6Prefix);
        } catch (Throwable $exception) {
            throw IdentityUnavailableException::because("The identity for {$this->scopeName($rule)} could not be resolved: the client IP could not be read: {$exception->getMessage()}", $exception);
        }
    }

    /**
     * How errors name a rule's scope: "user", or "custom:plan".
     */
    private function scopeName(CompiledRule $rule): string
    {
        return in_array($rule->scope, [Scope::Header, Scope::Custom], true)
            ? "{$rule->scope->value}:{$rule->scopeParameter}"
            : $rule->scope->value;
    }

    /**
     * The user and its class, so two user models with the same id never share a counter.
     *
     * @throws IdentityUnavailableException
     */
    private function user(): ?string
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
    private function custom(string $name): ?string
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
