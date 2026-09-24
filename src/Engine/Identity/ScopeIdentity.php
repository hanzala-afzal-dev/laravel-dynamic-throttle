<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Identity;

use Afzal\DynamicThrottle\Manifest\CompiledRule;
use LogicException;

final class ScopeIdentity
{
    public static function for(CompiledRule $rule, ?string $value, string $ip): ?string
    {
        throw new LogicException('Not implemented yet (SPEC-008).');
    }
}
