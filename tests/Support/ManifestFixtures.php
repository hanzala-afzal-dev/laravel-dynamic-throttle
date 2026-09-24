<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;

/**
 * Manifests written the way the compiler writes them (SPEC-006).
 */
final class ManifestFixtures
{
    /**
     * One compiled rule as stored in the manifest (SPEC-006 AC-2).
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function rule(array $overrides = []): array
    {
        return [
            'id' => '1',
            'name' => 'API',
            'method' => '*',
            'pattern' => 'api/*',
            'scope' => 'ip',
            'scope_parameter' => null,
            'max_attempts' => 60,
            'window_seconds' => 60,
            'priority' => 100,
            'mode' => 'enforce',
            'on_failure' => 'open',
            'on_missing_identity' => 'ip',
            'response_override' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     */
    public static function json(int $version, array $rules = [[]]): string
    {
        return ManifestCodec::encode($version, array_map(
            fn (array $overrides): CompiledRule => CompiledRule::fromArray(self::rule($overrides)),
            $rules,
        ));
    }
}
