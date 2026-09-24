<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Closure;
use LogicException;

/**
 * The package's entry point for apps, behind the DynamicThrottle facade.
 */
final class DynamicThrottle
{
    /**
     * @param  Closure|ScopeResolver|class-string<ScopeResolver>  $resolver
     */
    public function scope(string $name, Closure|ScopeResolver|string $resolver): void
    {
        throw new LogicException('Not implemented yet (SPEC-008).');
    }

    /**
     * @internal
     *
     * @return Closure|ScopeResolver|class-string<ScopeResolver>|null
     */
    public function scopeResolver(string $name): Closure|ScopeResolver|string|null
    {
        throw new LogicException('Not implemented yet (SPEC-008).');
    }
}
