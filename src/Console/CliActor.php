<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

/**
 * The CLI's actor in the audit log: cli:<system user> (ADR-0015 point 3), the user
 * that runs the command. Without the posix extension, the USER environment
 * variable, then "unknown" (SPEC-013).
 *
 * @internal
 */
final class CliActor
{
    public static function name(): string
    {
        $entry = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;
        $user = is_array($entry) ? $entry['name'] : getenv('USER');

        return 'cli:'.(is_string($user) && $user !== '' ? $user : 'unknown');
    }
}
