<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Http\Dashboard;

/**
 * The dashboard's routes (SPEC-018). A stub until its implementation.
 *
 * @internal
 */
final class DashboardRoutes
{
    /**
     * @param  'read'|'write'  $ability
     * @return list<string>
     */
    public static function middleware(string $ability): array
    {
        return [];
    }
}
