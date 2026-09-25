<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Tests\Support;

use Afzal\DynamicThrottle\Manifest\CompiledExemption;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use Afzal\DynamicThrottle\Manifest\ManifestCodec;

/**
 * Manifests written the way the compiler writes them (SPEC-006, SPEC-009).
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
     * One compiled exemption as stored in the manifest (SPEC-009 AC-8): by default,
     * 203.0.113.0/24 exempt from every rule, for good.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function exemption(array $overrides = []): array
    {
        return [
            'id' => '1',
            'rule' => null,
            'subject' => 'ip',
            'parameter' => null,
            'match' => '203.0.113.0/24',
            'expires_at' => null,
            ...$overrides,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rules
     * @param  list<array<string, mixed>>  $exemptions
     */
    public static function json(int $version, array $rules = [[]], array $exemptions = []): string
    {
        return ManifestCodec::encode(
            $version,
            array_map(fn (array $overrides): CompiledRule => CompiledRule::fromArray(self::rule($overrides)), $rules),
            array_map(fn (array $overrides): CompiledExemption => CompiledExemption::fromArray(self::exemption($overrides)), $exemptions),
        );
    }
}
