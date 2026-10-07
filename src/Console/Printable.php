<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Text from the database or from an exception, as a command prints it (SPEC-013,
 * found by the review). Its control characters, C0, DEL and C1, become \xNN or
 * \u{NN} escapes, so a rule's name cannot move the cursor, retitle the terminal or
 * hide output. Its tags are escaped, so the console formatter never reads <fg=…>
 * as a style, which would stop the command. And the commands' JSON.
 *
 * @internal
 */
final class Printable
{
    public static function text(string $value): string
    {
        // Bytes, not characters: C1 controls are U+0080 to U+009F, \xC2\x80 to \xC2\x9F in UTF-8.
        $visible = preg_replace_callback(
            '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/',
            static fn (array $match): string => strlen($match[0]) === 1 ? sprintf('\x%02X', ord($match[0])) : sprintf('\u{%X}', ord($match[0][1])),
            $value,
        );

        return OutputFormatter::escape($visible ?? $value);
    }

    /**
     * A value as JSON in pure ASCII, as explain prints it (SPEC-014 decision 6) and the
     * health check does (SPEC-016): every other character is escaped, a C1 control
     * too, and DEL, which JSON leaves as is, so nothing in it can drive the terminal.
     * A byte that is not UTF-8, such as from a path's %FF, becomes U+FFFD instead of
     * failing.
     */
    public static function json(mixed $value): string
    {
        return str_replace("\x7F", '\u007f', json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
    }
}
