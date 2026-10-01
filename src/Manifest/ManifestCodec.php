<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use InvalidArgumentException;
use JsonException;

/**
 * The manifest's JSON (SPEC-006). Decoding is strict: a manifest that decoded
 * wrongly would apply the wrong limits, so anything unexpected is corrupt.
 */
final class ManifestCodec
{
    /**
     * Bumped when the shape changes. Format 2 added the exemptions (SPEC-009), and
     * format 3 the runtime settings (SPEC-013): a server reads any other format as
     * corrupt, so the manifest is rebuilt.
     */
    public const int FORMAT = 3;

    private const int FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * @param  list<CompiledRule>  $rules
     * @param  list<CompiledExemption>  $exemptions
     */
    public static function encode(int $version, array $rules, array $exemptions = [], ?ManifestSettings $settings = null): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'version' => $version,
            'rules' => array_map(fn (CompiledRule $rule): array => $rule->toArray(), $rules),
            'exemptions' => array_map(fn (CompiledExemption $exemption): array => $exemption->toArray(), $exemptions),
            // An object even when empty, as it is keyed by setting.
            'settings' => (object) ($settings ?? ManifestSettings::none())->toArray(),
        ], self::FLAGS);
    }

    /**
     * One rule's JSON, as the manifest writes it. L1 keeps each rule's JSON, and builds
     * a rule only when a request matches it (SPEC-028).
     */
    public static function encodeRule(CompiledRule $rule): string
    {
        return json_encode($rule->toArray(), self::FLAGS);
    }

    /**
     * @throws InvalidManifest
     */
    public static function decodeRule(string $json): CompiledRule
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidManifest('A rule is not JSON: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidManifest('A rule must be a JSON object.');
        }

        try {
            return CompiledRule::fromArray($data);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidManifest('A rule is invalid: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }
    }

    /**
     * @throws InvalidManifest
     */
    public static function decode(string $json): Manifest
    {
        try {
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidManifest('The manifest is not JSON: '.$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new InvalidManifest('The manifest must be a JSON object.');
        }

        // First, so a manifest an older or newer version wrote is reported as such.
        if (($data['format'] ?? null) !== self::FORMAT) {
            throw new InvalidManifest('The manifest has format '.json_encode($data['format'] ?? null).', not '.self::FORMAT.'.');
        }

        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['exemptions', 'format', 'rules', 'settings', 'version']) {
            throw new InvalidManifest('The manifest must have exactly the keys format, version, rules, exemptions and settings.');
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            throw new InvalidManifest('The manifest version must be an integer of at least 1.');
        }

        if (! is_array($data['rules']) || ! array_is_list($data['rules'])) {
            throw new InvalidManifest('The manifest rules must be a list.');
        }

        if (! is_array($data['exemptions']) || ! array_is_list($data['exemptions'])) {
            throw new InvalidManifest('The manifest exemptions must be a list.');
        }

        $rules = [];

        foreach ($data['rules'] as $index => $rule) {
            try {
                $rules[] = is_array($rule)
                    ? CompiledRule::fromArray($rule)
                    : throw new InvalidArgumentException('A rule must be a JSON object.');
            } catch (InvalidArgumentException $exception) {
                throw new InvalidManifest("Rule #{$index} in the manifest is invalid: ".$exception->getMessage(), $exception->getCode(), previous: $exception);
            }
        }

        $exemptions = [];

        foreach ($data['exemptions'] as $index => $exemption) {
            try {
                $exemptions[] = is_array($exemption)
                    ? CompiledExemption::fromArray($exemption)
                    : throw new InvalidArgumentException('An exemption must be a JSON object.');
            } catch (InvalidArgumentException $exception) {
                throw new InvalidManifest("Exemption #{$index} in the manifest is invalid: ".$exception->getMessage(), $exception->getCode(), previous: $exception);
            }
        }

        try {
            $settings = ManifestSettings::fromArray($data['settings']);
        } catch (InvalidArgumentException $exception) {
            throw new InvalidManifest("The manifest's settings are invalid: ".$exception->getMessage(), $exception->getCode(), previous: $exception);
        }

        return new Manifest($data['version'], $rules, $exemptions, settings: $settings);
    }
}
