<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

use Afzal\DynamicThrottle\Manifest\ManifestSettings;
use InvalidArgumentException;

/**
 * The scale factor as admins write it, such as "1.5", and as the manifest holds
 * it, a whole percentage (ADR-0011, SPEC-013 decision 4). Parsed from its digits,
 * never through a float, so "1.1" is exactly 110.
 *
 * @internal
 */
final class ScaleFactor
{
    /**
     * @throws InvalidArgumentException for anything but 0.1 to 10 with at most two decimals
     */
    public static function percent(string $factor): int
    {
        if (preg_match('/\A(0|[1-9]\d?)(?:\.(\d{1,2}))?\z/', $factor, $parts) !== 1) {
            throw self::invalid($factor);
        }

        $percent = (int) $parts[1] * 100 + (int) str_pad($parts[2] ?? '', 2, '0');

        return $percent >= ManifestSettings::MIN_PERCENT && $percent <= ManifestSettings::MAX_PERCENT ? $percent : throw self::invalid($factor);
    }

    /**
     * The factor as admins write it, without trailing zeros: 150 is "1.5", 200 is "2".
     */
    public static function format(int $percent): string
    {
        $whole = (string) intdiv($percent, 100);
        $hundredths = rtrim(str_pad((string) ($percent % 100), 2, '0', STR_PAD_LEFT), '0');

        return $hundredths === '' ? $whole : "{$whole}.{$hundredths}";
    }

    private static function invalid(string $factor): InvalidArgumentException
    {
        return new InvalidArgumentException("The factor must be from 0.1 to 10, with at most two decimals, such as 1.5, got \"{$factor}\".");
    }
}
