<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support;

/**
 * Merges the app's config over the package defaults (ADR-0020 point 10, SPEC-005).
 *
 * Laravel's two helpers each lose something. mergeConfigFrom() merges only the
 * top level, so a config published by an older version never gets new nested
 * defaults. replaceConfigRecursivelyFrom() merges lists by index, so an entry a
 * user removed from a default list comes back. Here maps merge key by key and
 * lists are replaced whole.
 *
 * @internal
 */
final class ConfigMerger
{
    /**
     * @param  array<array-key, mixed>  $defaults
     * @param  array<array-key, mixed>  $app
     * @return array<array-key, mixed>
     */
    public static function merge(array $defaults, array $app): array
    {
        foreach ($app as $key => $value) {
            $default = $defaults[$key] ?? null;

            // An empty array where a map is expected keeps the defaults. A non-empty list
            // there replaces them, so validation can report it.
            $defaults[$key] = self::isMap($default) && is_array($value) && ($value === [] || ! array_is_list($value))
                ? self::merge($default, $value)
                : $value;
        }

        return $defaults;
    }

    /**
     * @phpstan-assert-if-true array<array-key, mixed> $value
     */
    private static function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }
}
