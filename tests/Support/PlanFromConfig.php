<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Contracts\ScopeResolver;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * A custom scope resolved from the container: it needs the config, and counts
 * how often it was built.
 */
final class PlanFromConfig implements ScopeResolver
{
    public static int $instances = 0;

    public function __construct(private readonly Repository $config)
    {
        self::$instances++;
    }

    public function resolve(Request $request): ?string
    {
        $plan = $this->config->get('test.plan');

        return is_string($plan) ? $plan : null;
    }
}
