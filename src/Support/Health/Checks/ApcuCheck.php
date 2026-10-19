<?php

declare(strict_types=1);

namespace Afzal\DynamicThrottle\Support\Health\Checks;

use Afzal\DynamicThrottle\Support\Health\Check;
use Afzal\DynamicThrottle\Support\Health\CheckResult;
use Afzal\DynamicThrottle\Support\Health\HealthContext;

/**
 * Issue #100: PHP-FPM without APCu. A stub, for the red tests.
 *
 * @internal
 */
final readonly class ApcuCheck implements Check
{
    public function id(): string
    {
        return 'apcu';
    }

    public function run(HealthContext $context): CheckResult
    {
        return CheckResult::ok($this->id(), 'Not checked yet.');
    }
}
