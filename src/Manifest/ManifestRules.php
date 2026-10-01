<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Manifest;

use LogicException;

/**
 * A manifest's rules by position (SPEC-028).
 *
 * @internal
 */
final class ManifestRules
{
    /**
     * @param  list<CompiledRule>  $rules
     */
    public static function of(array $rules): self
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.count($rules));
    }

    /**
     * @param  list<string>  $json
     */
    public static function fromJson(array $json): self
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.count($json));
    }

    public function get(int $position): CompiledRule
    {
        throw new LogicException('Not implemented yet (SPEC-028): '.$position);
    }

    /**
     * @return list<CompiledRule>
     */
    public function all(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028)');
    }

    /**
     * @return list<string>
     */
    public function json(): array
    {
        throw new LogicException('Not implemented yet (SPEC-028)');
    }
}
