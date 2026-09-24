<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Facades;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Closure;
use Illuminate\Support\Facades\Facade;

/**
 * @method static void scope(string $name, Closure|ScopeResolver|class-string<ScopeResolver> $resolver)
 *
 * @see \Afzal\DynamicThrottle\DynamicThrottle
 */
final class DynamicThrottle extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Afzal\DynamicThrottle\DynamicThrottle::class;
    }
}
