<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

/**
 * The CLI's actor in the audit log: cli:<system user> (ADR-0015 point 3), the user
 * that runs the command. Without the posix extension, the USER environment
 * variable, then USERNAME, which is Windows' (issue #96), then "unknown" (SPEC-013).
 * Not get_current_user(): it names the script file's owner, who may not be the one
 * running the command. Through sudo, the system user is root for everyone, so the
 * actor also names the user who ran sudo (the review).
 *
 * @internal
 */
final class CliActor
{
    public static function name(): string
    {
        $entry = function_exists('posix_getpwuid') && function_exists('posix_geteuid') ? posix_getpwuid(posix_geteuid()) : false;

        return self::named(is_array($entry) ? $entry['name'] : null);
    }

    /**
     * The actor for the system user the posix extension names, or null when it can't:
     * without the extension, as on Windows, or for a user it has no entry for.
     */
    public static function named(?string $systemUser): string
    {
        $user = $systemUser ?? self::environment('USER') ?? self::environment('USERNAME') ?? 'unknown';
        $user = $user !== '' ? $user : 'unknown';
        $sudo = getenv('SUDO_USER');

        return "cli:{$user}".(is_string($sudo) && $sudo !== '' && $sudo !== $user ? " (sudo by {$sudo})" : '');
    }

    private static function environment(string $name): ?string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : null;
    }
}
