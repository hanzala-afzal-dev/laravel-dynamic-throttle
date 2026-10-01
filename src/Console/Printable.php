<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Console;

use Symfony\Component\Console\Formatter\OutputFormatter;

/**
 * Text from the database or from an exception, as a command prints it (SPEC-013,
 * found by the review). Its control characters, C0, DEL and C1, become \xNN or
 * \u{NN} escapes, so a rule's name cannot move the cursor, retitle the terminal or
 * hide output. Its tags are escaped, so the console formatter never reads <fg=…>
 * as a style, which would stop the command.
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
}
