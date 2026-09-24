<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Exceptions\StorageUnavailableException;
use Afzal\DynamicThrottle\Manifest\CompiledRule;

/**
 * A rule whose counter could not be used (SPEC-007).
 */
final readonly class RuleFailure
{
    public function __construct(
        public CompiledRule $rule,
        public StorageUnavailableException $exception,
    ) {}
}
