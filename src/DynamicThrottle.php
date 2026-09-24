<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Closure;
use InvalidArgumentException;

/**
 * The package's entry point for apps, behind the DynamicThrottle facade. It
 * lives for the worker: apps register their scopes once, at boot.
 */
final class DynamicThrottle
{
    /** @var array<string, Closure|ScopeResolver|class-string<ScopeResolver>> */
    private array $scopes = [];

    /**
     * Registers a custom scope (ADR-0009). Rules with the custom scope $name count
     * each request under what the resolver returns for it: a string, an integer or
     * a Stringable, or null (or '') when the request has none.
     *
     * A class name is resolved from the container for each request that needs it.
     * Registering a name again replaces its resolver.
     *
     * @param  Closure|ScopeResolver|class-string<ScopeResolver>  $resolver  a closure receives the request
     *
     * @throws InvalidArgumentException for a name that is not 1 to 64 characters from a-z,
     *                                  0-9, "_" and "-", or a class that is not a ScopeResolver
     */
    public function scope(string $name, Closure|ScopeResolver|string $resolver): void
    {
        // The rule ids' alphabet: names are stored in rules' scope_parameter.
        if (preg_match('/\A[a-z0-9_-]{1,64}\z/', $name) !== 1) {
            throw new InvalidArgumentException("A scope name must be 1 to 64 characters from a-z, 0-9, \"_\" and \"-\", got \"{$name}\".");
        }

        if (is_string($resolver) && ! is_subclass_of($resolver, ScopeResolver::class)) {
            throw new InvalidArgumentException("The \"{$name}\" scope needs a closure or a ScopeResolver, and {$resolver} is not one.");
        }

        $this->scopes[$name] = $resolver;
    }

    /**
     * @internal
     *
     * @return Closure|ScopeResolver|class-string<ScopeResolver>|null
     */
    public function scopeResolver(string $name): Closure|ScopeResolver|string|null
    {
        return $this->scopes[$name] ?? null;
    }
}
