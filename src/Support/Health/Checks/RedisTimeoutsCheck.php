<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * Issue #99: how long a Redis that hangs holds a request. A stub, for the red tests.
 *
 * @internal
 */
final readonly class RedisTimeoutsCheck implements Check
{
    public function id(): string
    {
        return 'redis-timeouts';
    }

    public function run(HealthContext $context): CheckResult
    {
        return CheckResult::skipped($this->id(), 'Not checked yet.');
    }
}
