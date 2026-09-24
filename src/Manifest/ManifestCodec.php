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
    /** Bumped when the shape changes, such as when exemptions arrive (SPEC-009). */
    public const int FORMAT = 1;

    /**
     * @param  list<CompiledRule>  $rules
     */
    public static function encode(int $version, array $rules): string
    {
        return json_encode([
            'format' => self::FORMAT,
            'version' => $version,
            'rules' => array_map(fn (CompiledRule $rule): array => $rule->toArray(), $rules),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
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

        $keys = array_keys($data);
        sort($keys);

        if ($keys !== ['format', 'rules', 'version']) {
            throw new InvalidManifest('The manifest must have exactly the keys format, version and rules.');
        }

        if ($data['format'] !== self::FORMAT) {
            throw new InvalidManifest('The manifest has format '.json_encode($data['format']).', not '.self::FORMAT.'.');
        }

        if (! is_int($data['version']) || $data['version'] < 1) {
            throw new InvalidManifest('The manifest version must be an integer of at least 1.');
        }

        if (! is_array($data['rules']) || ! array_is_list($data['rules'])) {
            throw new InvalidManifest('The manifest rules must be a list.');
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

        return new Manifest($data['version'], $rules);
    }
}
