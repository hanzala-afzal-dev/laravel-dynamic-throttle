<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Engine\Stacking;

use Afzal\DynamicThrottle\Engine\Contracts\IdentityResolver;
use Afzal\DynamicThrottle\Engine\Contracts\LimiterStore;
use Afzal\DynamicThrottle\Manifest\CompiledRule;
use LogicException;

final readonly class RuleEvaluator
{
    public function __construct(
        private LimiterStore $store,
    ) {}

    /**
     * @param  list<CompiledRule>  $rules
     */
    public function evaluate(array $rules, IdentityResolver $identities): Evaluation
    {
        throw new LogicException('Not implemented yet (SPEC-007): '.$this->store::class);
    }
}
